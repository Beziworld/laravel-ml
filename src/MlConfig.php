<?php

declare(strict_types=1);

namespace Beziworld\Ml;

use Beziworld\Ml\Exceptions\InvalidConfiguration;
use Beziworld\Ml\Training\Validation;

/**
 * Validated, immutable view of the "ml" configuration file.
 */
final readonly class MlConfig
{
    public const array DEFAULT_NAMESPACES = ['Rubix\\ML\\', 'Tensor\\', 'OkBloomer\\', 'Beziworld\\Ml\\'];

    /**
     * @param array<string, class-string> $models
     * @param list<string> $allowedNamespaces
     */
    public function __construct(
        public string $disk = 'local',
        public string $path = 'ml-models',
        public bool $autoPromote = true,
        public int $keepVersions = 5,
        public array $models = [],
        public Validation $validation = new Validation(),
        public ?int $seed = null,
        public ?string $memoryLimit = null,
        public ?int $timeLimit = null,
        public ?string $queueConnection = null,
        public ?string $queueName = null,
        public int $maxVocabulary = 10000,
        public int $minDocumentCount = 1,
        public float $maxDocumentRatio = 1.0,
        public ?string $signingKey = null,
        public bool $requireSignature = true,
        public array $allowedNamespaces = self::DEFAULT_NAMESPACES,
        public bool $predictionEvents = false,
        public float $predictionSampleRate = 1.0,
        public float $driftMeanShift = 0.5,
        public float $driftDistributionShift = 0.2,
        public float $driftMissingShift = 0.1,
    ) {}

    /**
     * @param array<array-key, mixed> $config
     */
    public static function fromArray(array $config, ?string $appKey = null): self
    {
        $disk = self::string($config['disk'] ?? null);
        $path = self::string($config['path'] ?? null);

        if ($disk === null) {
            throw InvalidConfiguration::missing('disk', 'ML_DISK');
        }

        if ($path === null || trim($path, '/') === '') {
            throw InvalidConfiguration::missing('path', 'ML_PATH');
        }

        $validation = self::section($config, 'validation');
        $training = self::section($config, 'training');
        $queue = self::section($config, 'queue');
        $text = self::section($config, 'text');
        $security = self::section($config, 'security');
        $events = self::section($config, 'events');
        $drift = self::section($config, 'drift');

        $strategy = self::string($validation['strategy'] ?? null) ?? Validation::HOLDOUT;

        if (! in_array($strategy, [Validation::HOLDOUT, Validation::KFOLD, Validation::NONE], true)) {
            throw InvalidConfiguration::invalid('validation.strategy', 'ML_VALIDATION', 'expected holdout, kfold or none');
        }

        $ratio = self::float($validation['ratio'] ?? null, 0.2);

        if ($ratio <= 0.0 || $ratio >= 1.0) {
            throw InvalidConfiguration::invalid('validation.ratio', 'ML_HOLDOUT_RATIO', 'expected a number between 0 and 1');
        }

        $models = [];

        foreach (self::section($config, 'models') as $alias => $class) {
            if (is_string($alias) && is_string($class) && class_exists($class)) {
                $models[$alias] = $class;
            }
        }

        $namespaces = [];

        foreach (is_array($security['allowed_namespaces'] ?? null) ? $security['allowed_namespaces'] : self::DEFAULT_NAMESPACES as $namespace) {
            if (is_string($namespace) && $namespace !== '') {
                $namespaces[] = $namespace;
            }
        }

        $timeLimit = $training['time_limit'] ?? null;

        return new self(
            disk: $disk,
            path: trim($path, '/'),
            autoPromote: self::bool($config['auto_promote'] ?? null, true),
            keepVersions: self::int($config['keep_versions'] ?? null, 5, 1),
            models: $models,
            validation: new Validation($strategy, $ratio, self::int($validation['folds'] ?? null, 5, 2)),
            seed: is_numeric($training['seed'] ?? null) ? (int) $training['seed'] : null,
            memoryLimit: self::string($training['memory_limit'] ?? null),
            timeLimit: is_numeric($timeLimit) && (int) $timeLimit > 0 ? (int) $timeLimit : null,
            queueConnection: self::string($queue['connection'] ?? null),
            queueName: self::string($queue['name'] ?? null),
            maxVocabulary: self::int($text['max_vocabulary'] ?? null, 10000, 1),
            minDocumentCount: self::int($text['min_document_count'] ?? null, 1, 1),
            maxDocumentRatio: min(1.0, max(0.01, self::float($text['max_document_ratio'] ?? null, 1.0))),
            signingKey: self::string($security['signing_key'] ?? null) ?? self::string($appKey),
            requireSignature: self::bool($security['require_signature'] ?? null, true),
            allowedNamespaces: $namespaces === [] ? self::DEFAULT_NAMESPACES : $namespaces,
            predictionEvents: self::bool($events['predictions'] ?? null, false),
            predictionSampleRate: min(1.0, max(0.0, self::float($events['prediction_sample_rate'] ?? null, 1.0))),
            driftMeanShift: self::float($drift['mean_shift'] ?? null, 0.5),
            driftDistributionShift: self::float($drift['distribution_shift'] ?? null, 0.2),
            driftMissingShift: self::float($drift['missing_shift'] ?? null, 0.1),
        );
    }

    /**
     * @param array<array-key, mixed> $config
     * @return array<array-key, mixed>
     */
    private static function section(array $config, string $key): array
    {
        return is_array($config[$key] ?? null) ? $config[$key] : [];
    }

    private static function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private static function int(mixed $value, int $default, int $min): int
    {
        return is_numeric($value) && (int) $value >= $min ? (int) $value : $default;
    }

    private static function float(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    private static function bool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $default;
    }
}
