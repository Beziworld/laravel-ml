<?php

declare(strict_types=1);

namespace Beziworld\Ml\Persistence;

use Beziworld\Ml\Data\ModelMetadata;
use Beziworld\Ml\Exceptions\InvalidModelDefinition;
use Beziworld\Ml\Exceptions\ModelNotFound;
use Beziworld\Ml\MlConfig;
use Beziworld\Ml\Models\TrainedModel;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use JsonException;

/**
 * Versioned model storage on a Laravel filesystem disk.
 *
 * Layout:
 *     {path}/{model}/v1/model.bin       gzip-compressed serialized TrainedModel
 *     {path}/{model}/v1/metadata.json   ModelMetadata
 *     {path}/{model}/active.json        {"version": "v1", "promoted_at": "...", "previous": null}
 */
final class ModelRepository
{
    private const string MODEL_FILE = 'model.bin';

    private const string METADATA_FILE = 'metadata.json';

    private const string ACTIVE_FILE = 'active.json';

    /** @var array<string, array{0: TrainedModel, 1: ModelMetadata}> */
    private array $loaded = [];

    public function __construct(
        private readonly FilesystemFactory $filesystem,
        private readonly MlConfig $config,
        private readonly ModelSerializer $serializer,
    ) {}

    public function disk(): Filesystem
    {
        return $this->filesystem->disk($this->config->disk);
    }

    /**
     * Names of all stored models.
     *
     * @return list<string>
     */
    public function models(): array
    {
        $names = array_map('basename', $this->disk()->directories($this->config->path));
        sort($names);

        return $names;
    }

    /**
     * All complete versions of a model, oldest first, with the active one flagged.
     *
     * @return list<ModelMetadata>
     */
    public function versions(string $name): array
    {
        $this->guardName($name);

        $active = $this->activeVersion($name);
        $versions = [];

        foreach ($this->disk()->directories($this->modelPath($name)) as $directory) {
            $version = basename($directory);

            if (preg_match('/^v\d+$/', $version) !== 1 || ! $this->disk()->exists($directory . '/' . self::METADATA_FILE)) {
                continue;
            }

            $versions[] = $this->readMetadata($name, $version)->withActive($version === $active);
        }

        usort($versions, static fn(ModelMetadata $a, ModelMetadata $b): int => $a->versionNumber() <=> $b->versionNumber());

        return $versions;
    }

    public function exists(string $name, ?string $version = null): bool
    {
        $this->guardName($name);

        if ($version === null) {
            return $this->versions($name) !== [];
        }

        return $this->validVersion($version) && $this->disk()->exists($this->versionPath($name, $version) . '/' . self::METADATA_FILE);
    }

    public function metadata(string $name, ?string $version = null): ModelMetadata
    {
        $this->guardName($name);

        $version ??= $this->activeVersion($name) ?? throw ModelNotFound::model($name);

        if (! $this->exists($name, $version)) {
            throw ModelNotFound::version($name, $version);
        }

        return $this->readMetadata($name, $version)->withActive($version === $this->activeVersion($name));
    }

    public function activeVersion(string $name): ?string
    {
        $this->guardName($name);

        $path = $this->modelPath($name) . '/' . self::ACTIVE_FILE;

        if (! $this->disk()->exists($path)) {
            return null;
        }

        $data = $this->decodeJson((string) $this->disk()->get($path));
        $version = $data['version'] ?? null;

        return is_string($version) && $this->validVersion($version) ? $version : null;
    }

    public function nextVersion(string $name): string
    {
        $last = 0;

        foreach ($this->disk()->directories($this->modelPath($name)) as $directory) {
            if (preg_match('/^v(\d+)$/', basename($directory), $match) === 1) {
                $last = max($last, (int) $match[1]);
            }
        }

        return 'v' . ($last + 1);
    }

    /**
     * Write a new version and return its sealed metadata.
     */
    public function store(TrainedModel $model, ModelMetadata $draft): ModelMetadata
    {
        $this->guardName($draft->name);

        $encoded = $this->serializer->encode($model);
        $version = $this->nextVersion($draft->name);
        $metadata = $draft->sealed($version, $encoded['checksum'], $encoded['signature'], $encoded['classes']);
        $path = $this->versionPath($draft->name, $version);

        $this->disk()->put($path . '/' . self::MODEL_FILE, $encoded['payload']);
        $this->disk()->put($path . '/' . self::METADATA_FILE, json_encode($metadata->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

        return $metadata;
    }

    /**
     * Load and verify a version (the active one by default). Loaded models are cached per process.
     *
     * @return array{0: TrainedModel, 1: ModelMetadata}
     */
    public function load(string $name, ?string $version = null): array
    {
        $metadata = $this->metadata($name, $version);
        $cacheKey = $name . '@' . $metadata->version;

        if (isset($this->loaded[$cacheKey]) && $this->loaded[$cacheKey][1]->checksum === $metadata->checksum) {
            return [$this->loaded[$cacheKey][0], $metadata];
        }

        $payload = (string) $this->disk()->get($this->versionPath($name, $metadata->version) . '/' . self::MODEL_FILE);
        $model = $this->serializer->decode($payload, $metadata);

        $this->loaded[$cacheKey] = [$model, $metadata];

        return [$model, $metadata];
    }

    /**
     * Make a version active; returns the previously active version.
     */
    public function promote(string $name, string $version): ?string
    {
        if (! $this->exists($name, $version)) {
            throw ModelNotFound::version($name, $version);
        }

        $previous = $this->activeVersion($name);

        $this->disk()->put($this->modelPath($name) . '/' . self::ACTIVE_FILE, json_encode([
            'version' => $version,
            'previous' => $previous,
            'promoted_at' => date(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $previous;
    }

    /**
     * Activate the newest version older than the active one; returns the new active version.
     */
    public function rollback(string $name): string
    {
        $active = $this->activeVersion($name) ?? throw ModelNotFound::model($name);
        $activeNumber = (int) ltrim($active, 'v');
        $candidates = array_filter($this->versions($name), static fn(ModelMetadata $metadata): bool => $metadata->versionNumber() < $activeNumber);

        if ($candidates === []) {
            throw ModelNotFound::version($name, 'older than ' . $active);
        }

        $target = end($candidates)->version;
        $this->promote($name, $target);

        return $target;
    }

    /**
     * Delete all but the newest $keep versions; the active version is always kept.
     *
     * @return list<string> deleted versions
     */
    public function prune(string $name, ?int $keep = null): array
    {
        $keep = max(1, $keep ?? $this->config->keepVersions);
        $versions = $this->versions($name);
        $deleted = [];

        foreach (array_slice(array_reverse($versions), $keep) as $metadata) {
            if ($metadata->active) {
                continue;
            }

            $this->delete($name, $metadata->version);
            $deleted[] = $metadata->version;
        }

        sort($deleted, SORT_NATURAL);

        return $deleted;
    }

    public function delete(string $name, string $version): void
    {
        if ($this->activeVersion($name) === $version) {
            throw InvalidModelDefinition::because($name, sprintf('version %s is active; promote another version first.', $version));
        }

        $this->disk()->deleteDirectory($this->versionPath($name, $version));
        unset($this->loaded[$name . '@' . $version]);
    }

    public function forgetLoaded(): void
    {
        $this->loaded = [];
    }

    private function readMetadata(string $name, string $version): ModelMetadata
    {
        $json = (string) $this->disk()->get($this->versionPath($name, $version) . '/' . self::METADATA_FILE);

        return ModelMetadata::fromArray($this->decodeJson($json));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeJson(string $json): array
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function modelPath(string $name): string
    {
        return $this->config->path . '/' . $name;
    }

    private function versionPath(string $name, string $version): string
    {
        if (! $this->validVersion($version)) {
            throw ModelNotFound::version($name, $version);
        }

        return $this->modelPath($name) . '/' . $version;
    }

    private function validVersion(string $version): bool
    {
        return preg_match('/^v\d+$/', $version) === 1;
    }

    private function guardName(string $name): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) !== 1 || str_contains($name, '..')) {
            throw InvalidModelDefinition::because($name, 'model names may contain letters, digits, dots, dashes and underscores only.');
        }
    }
}
