<?php

declare(strict_types=1);

namespace Beziworld\Ml\Data;

use Carbon\CarbonImmutable;
use JsonSerializable;

/**
 * Outcome of a training run: validation metrics, the stored version and timing.
 *
 * Metric keys by task: classifier (accuracy, precision, recall, f1, mcc), regressor (mae, rmse, r2),
 * clusterer (silhouette, clusters and, with labels, v_measure, homogeneity, completeness),
 * anomaly detector (anomaly_rate and, with labels, accuracy, precision, recall, f1),
 * recommender (items).
 */
final readonly class TrainingReport implements JsonSerializable
{
    /**
     * @param array<string, float> $metrics
     * @param array<string, array<string, int>>|null $confusionMatrix actual class => predicted class => count
     */
    public function __construct(
        public string $model,
        public string $version,
        public string $task,
        public string $validation,
        public int $samples,
        public int $testSamples,
        public array $metrics,
        public ?array $confusionMatrix,
        public float $durationMs,
        public CarbonImmutable $trainedAt,
        public string $datasetHash,
        public bool $promoted,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $metrics = [];

        foreach (is_array($data['metrics'] ?? null) ? $data['metrics'] : [] as $name => $value) {
            if (is_numeric($value)) {
                $metrics[(string) $name] = (float) $value;
            }
        }

        $string = static fn(string $key): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';

        return new self(
            model: $string('model'),
            version: $string('version'),
            task: $string('task'),
            validation: $string('validation'),
            samples: is_numeric($data['samples'] ?? null) ? (int) $data['samples'] : 0,
            testSamples: is_numeric($data['test_samples'] ?? null) ? (int) $data['test_samples'] : 0,
            metrics: $metrics,
            confusionMatrix: self::matrix($data['confusion_matrix'] ?? null),
            durationMs: is_numeric($data['duration_ms'] ?? null) ? (float) $data['duration_ms'] : 0.0,
            trainedAt: CarbonImmutable::parse($string('trained_at') !== '' ? $string('trained_at') : 'now'),
            datasetHash: $string('dataset_hash'),
            promoted: (bool) ($data['promoted'] ?? false),
        );
    }

    public function metric(string $name): ?float
    {
        return $this->metrics[$name] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'version' => $this->version,
            'task' => $this->task,
            'validation' => $this->validation,
            'samples' => $this->samples,
            'test_samples' => $this->testSamples,
            'metrics' => $this->metrics,
            'confusion_matrix' => $this->confusionMatrix,
            'duration_ms' => $this->durationMs,
            'trained_at' => $this->trainedAt->toIso8601String(),
            'dataset_hash' => $this->datasetHash,
            'promoted' => $this->promoted,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string, array<string, int>>|null
     */
    public static function matrix(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $matrix = [];

        foreach ($value as $actual => $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach ($row as $predicted => $count) {
                $matrix[(string) $actual][(string) $predicted] = is_numeric($count) ? (int) $count : 0;
            }
        }

        return $matrix;
    }
}
