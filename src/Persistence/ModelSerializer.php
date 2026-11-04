<?php

declare(strict_types=1);

namespace Beziworld\Ml\Persistence;

use Beziworld\Ml\Data\ModelMetadata;
use Beziworld\Ml\Exceptions\IntegrityCheckFailed;
use Beziworld\Ml\MlConfig;
use Beziworld\Ml\Models\TrainedModel;
use Throwable;

/**
 * Encodes trained models into gzip-compressed PHP serialization and decodes them defensively.
 *
 * unserialize() can instantiate arbitrary classes and trigger magic methods, so a model file
 * is only decoded when:
 * 1. its SHA-256 checksum matches the metadata,
 * 2. its HMAC-SHA256 signature (over the checksum and the class list) matches the signing key,
 * 3. every class recorded at save time belongs to an allowed namespace,
 * and unserialize() then runs with allowed_classes restricted to that recorded list.
 */
final readonly class ModelSerializer
{
    public function __construct(private MlConfig $config) {}

    /**
     * @return array{payload: string, checksum: string, signature: string|null, classes: list<string>}
     */
    public function encode(TrainedModel $model): array
    {
        $serialized = serialize($model);
        $classes = self::classesIn($serialized);
        $payload = gzencode($serialized, 6);

        if ($payload === false) {
            throw IntegrityCheckFailed::because('model', 'new', 'compression failed.');
        }

        $checksum = hash('sha256', $payload);

        return [
            'payload' => $payload,
            'checksum' => $checksum,
            'signature' => $this->sign($checksum, $classes),
            'classes' => $classes,
        ];
    }

    public function decode(string $payload, ModelMetadata $metadata): TrainedModel
    {
        $fail = static fn(string $reason): IntegrityCheckFailed => IntegrityCheckFailed::because($metadata->name, $metadata->version, $reason);

        if (! hash_equals($metadata->checksum, hash('sha256', $payload))) {
            throw $fail('the SHA-256 checksum does not match the metadata (corrupted or modified file).');
        }

        $expected = $this->sign($metadata->checksum, $metadata->classes);

        if ($metadata->signature !== null && $expected !== null) {
            if (! hash_equals($expected, $metadata->signature)) {
                throw $fail('the HMAC signature is invalid (file modified or signed with another key).');
            }
        } elseif ($this->config->requireSignature) {
            throw $fail($expected === null
                ? 'a signature is required but no signing key is configured (ML_SIGNING_KEY or APP_KEY).'
                : 'the model is not signed and ml.security.require_signature is enabled.');
        }

        foreach ($metadata->classes as $class) {
            if (! $this->allowed($class)) {
                throw $fail(sprintf('class "%s" is outside the allowed namespaces.', $class));
            }
        }

        $serialized = @gzdecode($payload);

        if ($serialized === false) {
            throw $fail('the payload cannot be decompressed.');
        }

        try {
            $model = unserialize($serialized, ['allowed_classes' => $metadata->classes]);
        } catch (Throwable $exception) {
            throw $fail('unserialize failed: ' . $exception->getMessage());
        }

        if (! $model instanceof TrainedModel) {
            throw $fail('the payload does not contain a trained model.');
        }

        return $model;
    }

    /**
     * Class names referenced by a serialized string (objects, custom-serialized objects and enums).
     *
     * @return list<string>
     */
    public static function classesIn(string $serialized): array
    {
        preg_match_all('/(?<=^|[;{])[OCE]:\d+:"([^"]+)"/', $serialized, $matches);

        $classes = [];

        foreach ($matches[1] as $name) {
            $class = str_contains($name, ':') ? strstr($name, ':', true) : $name;

            if (is_string($class) && $class !== '') {
                $classes[$class] = true;
            }
        }

        $classes = array_keys($classes);
        sort($classes);

        return $classes;
    }

    private function allowed(string $class): bool
    {
        foreach ($this->config->allowedNamespaces as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $classes
     */
    private function sign(string $checksum, array $classes): ?string
    {
        if ($this->config->signingKey === null) {
            return null;
        }

        return hash_hmac('sha256', $checksum . "\n" . implode("\n", $classes), $this->config->signingKey);
    }
}
