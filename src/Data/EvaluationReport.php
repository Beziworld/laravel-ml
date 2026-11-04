<?php

declare(strict_types=1);

namespace Beziworld\Ml\Data;

use Carbon\CarbonImmutable;
use JsonSerializable;

/**
 * Metrics of a stored model version measured on a dataset (e.g. fresh production data).
 */
final readonly class EvaluationReport implements JsonSerializable
{
    /**
     * @param array<string, float> $metrics
     * @param array<string, array<string, int>>|null $confusionMatrix
     */
    public function __construct(
        public string $model,
        public string $version,
        public string $task,
        public int $samples,
        public array $metrics,
        public ?array $confusionMatrix,
        public CarbonImmutable $evaluatedAt,
    ) {}

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
            'samples' => $this->samples,
            'metrics' => $this->metrics,
            'confusion_matrix' => $this->confusionMatrix,
            'evaluated_at' => $this->evaluatedAt->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
