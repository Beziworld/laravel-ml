<?php

declare(strict_types=1);

namespace Beziworld\Ml\Dataset;

use Beziworld\Ml\Enums\FeatureType;
use Beziworld\Ml\Exceptions\InvalidDataset;
use Closure;

/**
 * Ordered list of features that turns records into Rubix samples.
 *
 * Accepted definitions:
 * - a list of attribute names: ['age', 'plan'] (types are inferred from the data),
 * - a map of names to types: ['age' => 'numeric', 'plan' => FeatureType::Categorical],
 * - a map of names to resolver closures: ['orders' => fn (User $user) => $user->orders()->count()],
 * - Feature instances: [Feature::text('message')].
 */
final readonly class FeatureSchema
{
    /**
     * @param list<Feature> $features
     */
    public function __construct(public array $features)
    {
        if ($features === []) {
            throw InvalidDataset::because('at least one feature is required.');
        }

        $names = array_map(static fn(Feature $feature): string => $feature->name, $features);

        if (count($names) !== count(array_unique($names))) {
            throw InvalidDataset::because('feature names must be unique.');
        }
    }

    /**
     * @param array<array-key, mixed> $definition
     */
    public static function from(array $definition): self
    {
        $features = [];

        foreach ($definition as $key => $value) {
            $features[] = match (true) {
                $value instanceof Feature => $value,
                is_int($key) && is_string($value) => new Feature($value),
                is_string($key) && $value instanceof FeatureType => new Feature($key, $value),
                is_string($key) && is_string($value) => new Feature($key, self::type($key, $value)),
                is_string($key) && $value instanceof Closure => new Feature($key, null, $value),
                is_string($key) && $value === null => new Feature($key),
                default => throw InvalidDataset::because(sprintf('cannot read the feature definition at key "%s".', $key)),
            };
        }

        return new self($features);
    }

    /**
     * Rebuild a schema from the metadata of a stored model.
     *
     * @param list<array{name: string, type: string|null}> $features
     */
    public static function fromArray(array $features): self
    {
        return new self(array_map(
            static fn(array $feature): Feature => new Feature($feature['name'], $feature['type'] !== null ? FeatureType::from($feature['type']) : null),
            $features,
        ));
    }

    /**
     * @return list<array{name: string, type: string|null}>
     */
    public function toArray(): array
    {
        return array_map(static fn(Feature $feature): array => $feature->toArray(), $this->features);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn(Feature $feature): string => $feature->name, $this->features);
    }

    public function count(): int
    {
        return count($this->features);
    }

    public function isResolved(): bool
    {
        foreach ($this->features as $feature) {
            if ($feature->type === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Column offsets of the features of the given types.
     *
     * @return list<int>
     */
    public function offsetsOf(FeatureType ...$types): array
    {
        $offsets = [];

        foreach ($this->features as $offset => $feature) {
            if ($feature->type !== null && in_array($feature->type, $types, true)) {
                $offsets[] = $offset;
            }
        }

        return $offsets;
    }

    public function has(FeatureType $type): bool
    {
        return $this->offsetsOf($type) !== [];
    }

    /**
     * Read the raw values of every feature from a record.
     *
     * @return list<mixed>
     */
    public function read(mixed $record): array
    {
        return array_map(static fn(Feature $feature): mixed => $feature->read($record), $this->features);
    }

    /**
     * Infer the missing types from raw rows produced by read().
     *
     * @param list<list<mixed>> $rows
     */
    public function resolve(array $rows): self
    {
        if ($this->isResolved()) {
            return $this;
        }

        $features = [];

        foreach ($this->features as $offset => $feature) {
            $features[] = $feature->type !== null
                ? $feature
                : $feature->withType(Feature::inferType(array_column(array_slice($rows, 0, 100), $offset)));
        }

        return new self($features);
    }

    /**
     * Take the types of a resolved schema (e.g. stored metadata) while keeping local resolvers.
     */
    public function withTypesFrom(self $resolved): self
    {
        $types = [];

        foreach ($resolved->features as $feature) {
            $types[$feature->name] = $feature->type;
        }

        $features = [];

        foreach ($this->features as $feature) {
            $type = $types[$feature->name] ?? $feature->type;
            $features[] = $type !== null ? $feature->withType($type) : $feature;
        }

        return new self($features);
    }

    /**
     * Cast raw values produced by read() into a sample.
     *
     * @param list<mixed> $raw
     * @return list<float|string>
     */
    public function cast(array $raw): array
    {
        $sample = [];

        foreach ($this->features as $offset => $feature) {
            $sample[] = $feature->cast($raw[$offset] ?? null);
        }

        return $sample;
    }

    /**
     * Read and cast a single record.
     *
     * @return list<float|string>
     */
    public function extract(mixed $record): array
    {
        return $this->cast($this->read($record));
    }

    private static function type(string $name, string $type): FeatureType
    {
        return FeatureType::tryFrom(strtolower($type))
            ?? throw InvalidDataset::because(sprintf('feature "%s" has an unknown type "%s" (numeric, categorical, boolean, date or text).', $name, $type));
    }
}
