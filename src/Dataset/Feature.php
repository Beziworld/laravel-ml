<?php

declare(strict_types=1);

namespace Beziworld\Ml\Dataset;

use BackedEnum;
use Beziworld\Ml\Enums\FeatureType;
use Beziworld\Ml\Exceptions\InvalidDataset;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * One input attribute of a model: its name, how it is read from a record and how it is cast.
 *
 * Without an explicit type the type is inferred from the training data. A resolver closure
 * can compute the value from the record (e.g. a relation count) instead of reading an
 * attribute with data_get().
 */
final readonly class Feature
{
    /** Placeholder used by Rubix ML for a missing categorical value. */
    public const string MISSING = '?';

    /** Seconds in a day, used to express dates as days since the Unix epoch. */
    private const int DAY = 86400;

    /**
     * @param (Closure(mixed): mixed)|null $resolver
     */
    public function __construct(
        public string $name,
        public ?FeatureType $type = null,
        public ?Closure $resolver = null,
    ) {
        if (trim($name) === '') {
            throw InvalidDataset::because('a feature name cannot be empty.');
        }
    }

    /**
     * @param (Closure(mixed): mixed)|null $resolver
     */
    public static function numeric(string $name, ?Closure $resolver = null): self
    {
        return new self($name, FeatureType::Numeric, $resolver);
    }

    /**
     * @param (Closure(mixed): mixed)|null $resolver
     */
    public static function categorical(string $name, ?Closure $resolver = null): self
    {
        return new self($name, FeatureType::Categorical, $resolver);
    }

    /**
     * @param (Closure(mixed): mixed)|null $resolver
     */
    public static function boolean(string $name, ?Closure $resolver = null): self
    {
        return new self($name, FeatureType::Boolean, $resolver);
    }

    /**
     * @param (Closure(mixed): mixed)|null $resolver
     */
    public static function date(string $name, ?Closure $resolver = null): self
    {
        return new self($name, FeatureType::Date, $resolver);
    }

    /**
     * @param (Closure(mixed): mixed)|null $resolver
     */
    public static function text(string $name, ?Closure $resolver = null): self
    {
        return new self($name, FeatureType::Text, $resolver);
    }

    public function withType(FeatureType $type): self
    {
        return new self($this->name, $type, $this->resolver);
    }

    /**
     * Read the raw value of the feature from an array, an Eloquent model or any object.
     */
    public function read(mixed $record): mixed
    {
        if ($this->resolver instanceof Closure) {
            return ($this->resolver)($record);
        }

        return data_get($record, $this->name);
    }

    /**
     * Cast a raw value into a Rubix sample value (float for continuous types, string otherwise).
     */
    public function cast(mixed $value): float|string
    {
        return match ($this->type ?? FeatureType::Categorical) {
            FeatureType::Numeric => $this->castNumeric($value),
            FeatureType::Boolean => $this->castBoolean($value),
            FeatureType::Date => $this->castDate($value),
            FeatureType::Categorical => self::castCategorical($value),
            FeatureType::Text => $this->castText($value),
        };
    }

    /**
     * Guess the type of a feature from a few raw values.
     *
     * @param iterable<mixed> $values
     */
    public static function inferType(iterable $values): FeatureType
    {
        $longText = false;
        $seen = false;

        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_bool($value)) {
                return FeatureType::Boolean;
            }

            if (is_int($value) || is_float($value)) {
                return FeatureType::Numeric;
            }

            if ($value instanceof DateTimeInterface) {
                return FeatureType::Date;
            }

            if ($value instanceof UnitEnum) {
                return FeatureType::Categorical;
            }

            if (is_string($value)) {
                if (is_numeric($value)) {
                    return FeatureType::Numeric;
                }

                $seen = true;
                $longText = $longText || (str_contains(trim($value), ' ') && mb_strlen($value) > 30);
            }
        }

        return $seen && $longText ? FeatureType::Text : FeatureType::Categorical;
    }

    /**
     * @return array{name: string, type: string|null}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'type' => $this->type?->value];
    }

    public static function castCategorical(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::MISSING;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw InvalidDataset::because(sprintf('a categorical feature received %s, a scalar value was expected.', get_debug_type($value)));
    }

    private function castNumeric(mixed $value): float
    {
        if ($value === null || $value === '') {
            return NAN;
        }

        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        throw $this->mismatch('a number', $value);
    }

    private function castBoolean(mixed $value): float
    {
        if ($value === null || $value === '') {
            return NAN;
        }

        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0 ? 1.0 : 0.0;
        }

        if (is_string($value)) {
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($bool !== null) {
                return $bool ? 1.0 : 0.0;
            }
        }

        throw $this->mismatch('a boolean', $value);
    }

    private function castDate(mixed $value): float
    {
        if ($value === null || $value === '') {
            return NAN;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp() / self::DAY;
        }

        if (is_int($value) || is_float($value)) {
            return $value / self::DAY;
        }

        if (is_string($value)) {
            try {
                return (new DateTimeImmutable($value))->getTimestamp() / self::DAY;
            } catch (Throwable) {
                throw $this->mismatch('a date', $value);
            }
        }

        throw $this->mismatch('a date', $value);
    }

    private function castText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            return implode(' ', array_map(
                static fn(mixed $part): string => is_scalar($part) || $part instanceof Stringable ? (string) $part : '',
                $value,
            ));
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        throw $this->mismatch('text', $value);
    }

    private function mismatch(string $expected, mixed $value): InvalidDataset
    {
        $shown = is_scalar($value) ? sprintf('"%s"', mb_strimwidth((string) $value, 0, 40, '...')) : get_debug_type($value);

        return InvalidDataset::because(sprintf('feature "%s" expects %s, got %s.', $this->name, $expected, $shown));
    }
}
