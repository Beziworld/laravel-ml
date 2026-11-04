<?php

declare(strict_types=1);

namespace Beziworld\Ml\Data;

use JsonSerializable;

/**
 * Result of comparing new data with the feature statistics stored at training time.
 */
final readonly class DriftReport implements JsonSerializable
{
    /**
     * @param list<FeatureDrift> $features
     */
    public function __construct(
        public string $model,
        public string $version,
        public int $samples,
        public array $features,
    ) {}

    public function drifted(): bool
    {
        foreach ($this->features as $feature) {
            if ($feature->drifted) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function driftedFeatures(): array
    {
        return array_values(array_map(
            static fn(FeatureDrift $feature): string => $feature->name,
            array_filter($this->features, static fn(FeatureDrift $feature): bool => $feature->drifted),
        ));
    }

    public function feature(string $name): ?FeatureDrift
    {
        foreach ($this->features as $feature) {
            if ($feature->name === $name) {
                return $feature;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'version' => $this->version,
            'samples' => $this->samples,
            'drifted' => $this->drifted(),
            'features' => array_map(static fn(FeatureDrift $feature): array => $feature->toArray(), $this->features),
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
