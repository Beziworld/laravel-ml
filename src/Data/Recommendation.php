<?php

declare(strict_types=1);

namespace Beziworld\Ml\Data;

use JsonSerializable;

/**
 * One recommended item: its key, the distance in feature space and a 0..1 similarity.
 */
final readonly class Recommendation implements JsonSerializable
{
    public function __construct(
        public int|string $key,
        public float $distance,
        public float $similarity,
    ) {}

    public static function fromDistance(int|string $key, float $distance): self
    {
        return new self($key, $distance, round(1.0 / (1.0 + $distance), 6));
    }

    /**
     * @return array{key: int|string, distance: float, similarity: float}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'distance' => $this->distance, 'similarity' => $this->similarity];
    }

    /**
     * @return array{key: int|string, distance: float, similarity: float}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
