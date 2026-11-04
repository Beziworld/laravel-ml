<?php

declare(strict_types=1);

namespace Beziworld\Ml\Data;

use JsonSerializable;

/**
 * Drift of one feature between the training data and new data.
 *
 * meanShift: |new mean - training mean| / training standard deviation (continuous features),
 * distributionShift: total variation distance of category shares (categorical features),
 * missingShift: absolute change of the missing value rate.
 */
final readonly class FeatureDrift implements JsonSerializable
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $drifted,
        public float $missingShift,
        public ?float $meanShift = null,
        public ?float $distributionShift = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'drifted' => $this->drifted,
            'missing_shift' => $this->missingShift,
            'mean_shift' => $this->meanShift,
            'distribution_shift' => $this->distributionShift,
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
