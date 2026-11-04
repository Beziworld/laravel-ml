<?php

declare(strict_types=1);

namespace Beziworld\Ml\Data;

use Carbon\CarbonImmutable;
use JsonSerializable;

/**
 * Contents of the metadata.json file stored next to every model version.
 */
final readonly class ModelMetadata implements JsonSerializable
{
    /**
     * @param list<array{name: string, type: string|null}> $features
     * @param array<string, float> $metrics
     * @param list<FeatureStatistics> $statistics
     * @param list<string> $classes classes found in the serialized payload (the unserialize allowlist)
     * @param array<string, mixed> $preprocessing
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $task,
        public string $estimator,
        public array $features,
        public ?string $label,
        public array $metrics,
        public string $validation,
        public int $samples,
        public CarbonImmutable $trainedAt,
        public string $datasetHash,
        public string $checksum,
        public ?string $signature,
        public array $classes,
        public array $statistics = [],
        public array $preprocessing = [],
        public float $durationMs = 0.0,
        public string $packageVersion = 'unknown',
        public string $rubixVersion = 'unknown',
        public string $phpVersion = PHP_VERSION,
        public bool $active = false,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, bool $active = false): self
    {
        $string = static fn(string $key, string $default = ''): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : $default;

        $features = [];

        foreach (is_array($data['features'] ?? null) ? $data['features'] : [] as $feature) {
            if (is_array($feature) && is_string($feature['name'] ?? null)) {
                $features[] = ['name' => $feature['name'], 'type' => is_string($feature['type'] ?? null) ? $feature['type'] : null];
            }
        }

        $metrics = [];

        foreach (is_array($data['metrics'] ?? null) ? $data['metrics'] : [] as $metric => $value) {
            if (is_numeric($value)) {
                $metrics[(string) $metric] = (float) $value;
            }
        }

        $statistics = [];

        foreach (is_array($data['statistics'] ?? null) ? $data['statistics'] : [] as $statistic) {
            if (is_array($statistic)) {
                /** @var array<string, mixed> $statistic */
                $statistics[] = FeatureStatistics::fromArray($statistic);
            }
        }

        $classes = array_values(array_filter(
            is_array($data['classes'] ?? null) ? $data['classes'] : [],
            static fn(mixed $class): bool => is_string($class),
        ));

        /** @var array<string, mixed> $preprocessing */
        $preprocessing = is_array($data['preprocessing'] ?? null) ? $data['preprocessing'] : [];
        /** @var array<string, mixed> $raw */
        $raw = $data;

        return new self(
            name: $string('name'),
            version: $string('version'),
            task: $string('task', 'classifier'),
            estimator: $string('estimator'),
            features: $features,
            label: is_string($data['label'] ?? null) ? $data['label'] : null,
            metrics: $metrics,
            validation: $string('validation', 'none'),
            samples: is_numeric($data['samples'] ?? null) ? (int) $data['samples'] : 0,
            trainedAt: CarbonImmutable::parse($string('trained_at', '1970-01-01T00:00:00+00:00')),
            datasetHash: $string('dataset_hash'),
            checksum: $string('checksum'),
            signature: is_string($data['signature'] ?? null) ? $data['signature'] : null,
            classes: array_map('strval', $classes),
            statistics: $statistics,
            preprocessing: $preprocessing,
            durationMs: is_numeric($data['duration_ms'] ?? null) ? (float) $data['duration_ms'] : 0.0,
            packageVersion: $string('package_version', 'unknown'),
            rubixVersion: $string('rubix_version', 'unknown'),
            phpVersion: $string('php_version', 'unknown'),
            active: $active,
            raw: $raw,
        );
    }

    public function metric(string $name): ?float
    {
        return $this->metrics[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function featureNames(): array
    {
        return array_column($this->features, 'name');
    }

    public function versionNumber(): int
    {
        return (int) ltrim($this->version, 'v');
    }

    public function statistic(string $feature): ?FeatureStatistics
    {
        foreach ($this->statistics as $statistic) {
            if ($statistic->name === $feature) {
                return $statistic;
            }
        }

        return null;
    }

    /**
     * Copy with the storage fields filled in by the repository.
     *
     * @param list<string> $classes
     */
    public function sealed(string $version, string $checksum, ?string $signature, array $classes): self
    {
        return new self(
            $this->name,
            $version,
            $this->task,
            $this->estimator,
            $this->features,
            $this->label,
            $this->metrics,
            $this->validation,
            $this->samples,
            $this->trainedAt,
            $this->datasetHash,
            $checksum,
            $signature,
            $classes,
            $this->statistics,
            $this->preprocessing,
            $this->durationMs,
            $this->packageVersion,
            $this->rubixVersion,
            $this->phpVersion,
            $this->active,
            $this->raw,
        );
    }

    public function withActive(bool $active): self
    {
        return new self(
            $this->name,
            $this->version,
            $this->task,
            $this->estimator,
            $this->features,
            $this->label,
            $this->metrics,
            $this->validation,
            $this->samples,
            $this->trainedAt,
            $this->datasetHash,
            $this->checksum,
            $this->signature,
            $this->classes,
            $this->statistics,
            $this->preprocessing,
            $this->durationMs,
            $this->packageVersion,
            $this->rubixVersion,
            $this->phpVersion,
            $active,
            $this->raw,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'task' => $this->task,
            'estimator' => $this->estimator,
            'features' => $this->features,
            'label' => $this->label,
            'metrics' => $this->metrics,
            'validation' => $this->validation,
            'samples' => $this->samples,
            'trained_at' => $this->trainedAt->toIso8601String(),
            'duration_ms' => $this->durationMs,
            'dataset_hash' => $this->datasetHash,
            'checksum' => $this->checksum,
            'signature' => $this->signature,
            'classes' => $this->classes,
            'statistics' => array_map(static fn(FeatureStatistics $statistic): array => $statistic->toArray(), $this->statistics),
            'preprocessing' => $this->preprocessing,
            'package_version' => $this->packageVersion,
            'rubix_version' => $this->rubixVersion,
            'php_version' => $this->phpVersion,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray() + ['active' => $this->active];
    }
}
