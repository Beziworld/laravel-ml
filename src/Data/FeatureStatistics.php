<?php

declare(strict_types=1);

namespace Beziworld\Ml\Data;

use JsonSerializable;

/**
 * Summary statistics of one feature, stored with every model version and used for drift checks.
 */
final readonly class FeatureStatistics implements JsonSerializable
{
    /**
     * @param array<string, float> $distribution category => share of non-missing values (categorical features)
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $name,
        public string $type,
        public int $count,
        public int $missing,
        public ?float $mean = null,
        public ?float $std = null,
        public ?float $min = null,
        public ?float $max = null,
        public array $distribution = [],
        public ?float $averageLength = null,
        public array $raw = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $float = static fn(mixed $value): ?float => is_numeric($value) ? (float) $value : null;
        $distribution = [];

        foreach (is_array($data['distribution'] ?? null) ? $data['distribution'] : [] as $category => $share) {
            if (is_numeric($share)) {
                $distribution[(string) $category] = (float) $share;
            }
        }

        return new self(
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            type: is_string($data['type'] ?? null) ? $data['type'] : 'numeric',
            count: is_numeric($data['count'] ?? null) ? (int) $data['count'] : 0,
            missing: is_numeric($data['missing'] ?? null) ? (int) $data['missing'] : 0,
            mean: $float($data['mean'] ?? null),
            std: $float($data['std'] ?? null),
            min: $float($data['min'] ?? null),
            max: $float($data['max'] ?? null),
            distribution: $distribution,
            averageLength: $float($data['average_length'] ?? null),
            raw: $data,
        );
    }

    public function missingRate(): float
    {
        return $this->count > 0 ? $this->missing / $this->count : 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'type' => $this->type,
            'count' => $this->count,
            'missing' => $this->missing,
            'mean' => $this->mean,
            'std' => $this->std,
            'min' => $this->min,
            'max' => $this->max,
            'distribution' => $this->distribution === [] ? null : $this->distribution,
            'average_length' => $this->averageLength,
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
