<?php

declare(strict_types=1);

namespace Beziworld\Ml\Data;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * JSON-friendly result of a single prediction.
 *
 * - value: predicted class (string), regression value (float), cluster number (int) or
 *   anomaly flag (int 0/1),
 * - probabilities: class => probability for probabilistic estimators,
 * - confidence: the highest probability,
 * - score: anomaly score (higher is more unusual),
 * - anomaly: whether an anomaly detector flagged the record.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class Prediction implements Arrayable, JsonSerializable
{
    /**
     * @param array<string, float> $probabilities
     */
    public function __construct(
        public string $model,
        public string $version,
        public string $task,
        public int|float|string $value,
        public array $probabilities = [],
        public ?float $confidence = null,
        public ?float $score = null,
        public ?bool $anomaly = null,
        public ?CarbonImmutable $predictedAt = null,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $probabilities = [];

        foreach (is_array($data['probabilities'] ?? null) ? $data['probabilities'] : [] as $class => $probability) {
            if (is_numeric($probability)) {
                $probabilities[(string) $class] = (float) $probability;
            }
        }

        $value = $data['value'] ?? '';

        return new self(
            model: is_scalar($data['model'] ?? null) ? (string) $data['model'] : '',
            version: is_scalar($data['version'] ?? null) ? (string) $data['version'] : '',
            task: is_scalar($data['task'] ?? null) ? (string) $data['task'] : '',
            value: is_int($value) || is_float($value) || is_string($value) ? $value : '',
            probabilities: $probabilities,
            confidence: is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : null,
            score: is_numeric($data['score'] ?? null) ? (float) $data['score'] : null,
            anomaly: is_bool($data['anomaly'] ?? null) ? $data['anomaly'] : null,
            predictedAt: is_string($data['predicted_at'] ?? null) ? CarbonImmutable::parse($data['predicted_at']) : null,
        );
    }

    /**
     * Whether the predicted value equals one of the given values (compared as strings).
     */
    public function is(int|float|string|bool ...$values): bool
    {
        foreach ($values as $value) {
            $expected = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

            if ((string) $this->value === $expected) {
                return true;
            }
        }

        return false;
    }

    public function probability(int|string|bool $class): ?float
    {
        $key = is_bool($class) ? ($class ? 'true' : 'false') : (string) $class;

        return $this->probabilities[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'model' => $this->model,
            'version' => $this->version,
            'task' => $this->task,
            'value' => $this->value,
            'probabilities' => $this->probabilities === [] ? null : $this->probabilities,
            'confidence' => $this->confidence,
            'score' => $this->score,
            'anomaly' => $this->anomaly,
            'predicted_at' => $this->predictedAt?->toIso8601String(),
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
