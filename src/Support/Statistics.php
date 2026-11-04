<?php

declare(strict_types=1);

namespace Beziworld\Ml\Support;

use Beziworld\Ml\Data\FeatureStatistics;
use Beziworld\Ml\Dataset\Feature;
use Beziworld\Ml\Dataset\FeatureSchema;
use Beziworld\Ml\Enums\FeatureType;

/**
 * Computes per-feature statistics of cast samples.
 */
final class Statistics
{
    /** Number of most frequent categories kept in a categorical distribution. */
    private const int TOP_CATEGORIES = 50;

    /** Bucket that collects the share of the less frequent categories. */
    public const string OTHER = '__other__';

    /**
     * @param iterable<list<float|string>> $samples
     * @return list<FeatureStatistics>
     */
    public static function describe(FeatureSchema $schema, iterable $samples): array
    {
        $count = 0;
        $missing = array_fill(0, $schema->count(), 0);
        $numbers = [];
        $categories = [];
        $lengths = [];

        foreach ($samples as $sample) {
            $count++;

            foreach ($schema->features as $offset => $feature) {
                $value = $sample[$offset] ?? null;
                $type = $feature->type ?? FeatureType::Categorical;

                if ($type->isContinuous()) {
                    if (! is_float($value) || is_nan($value)) {
                        $missing[$offset]++;
                    } else {
                        $numbers[$offset][] = $value;
                    }

                    continue;
                }

                $value = is_string($value) ? $value : '';

                if ($type === FeatureType::Text) {
                    $lengths[$offset][] = mb_strlen($value);

                    if (trim($value) === '') {
                        $missing[$offset]++;
                    }

                    continue;
                }

                if ($value === Feature::MISSING) {
                    $missing[$offset]++;
                } else {
                    $categories[$offset][$value] = ($categories[$offset][$value] ?? 0) + 1;
                }
            }
        }

        $statistics = [];

        foreach ($schema->features as $offset => $feature) {
            $type = $feature->type ?? FeatureType::Categorical;
            $values = $numbers[$offset] ?? [];
            [$mean, $std] = self::meanAndStd($values);

            $statistics[] = new FeatureStatistics(
                name: $feature->name,
                type: $type->value,
                count: $count,
                missing: $missing[$offset],
                mean: $mean,
                std: $std,
                min: $values === [] ? null : min($values),
                max: $values === [] ? null : max($values),
                distribution: self::distribution($categories[$offset] ?? []),
                averageLength: isset($lengths[$offset]) ? array_sum($lengths[$offset]) / count($lengths[$offset]) : null,
            );
        }

        return $statistics;
    }

    /**
     * @param list<float> $values
     * @return array{0: float|null, 1: float|null}
     */
    public static function meanAndStd(array $values): array
    {
        $n = count($values);

        if ($n === 0) {
            return [null, null];
        }

        $mean = array_sum($values) / $n;
        $variance = 0.0;

        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }

        return [$mean, sqrt($variance / $n)];
    }

    /**
     * @param array<array-key, int> $counts
     * @return array<string, float>
     */
    public static function distribution(array $counts): array
    {
        $total = array_sum($counts);

        if ($total === 0) {
            return [];
        }

        arsort($counts);

        $distribution = [];
        $other = 0;

        foreach ($counts as $category => $occurrences) {
            if (count($distribution) < self::TOP_CATEGORIES) {
                $distribution[(string) $category] = round($occurrences / $total, 6);
            } else {
                $other += $occurrences;
            }
        }

        if ($other > 0) {
            $distribution[self::OTHER] = round($other / $total, 6);
        }

        return $distribution;
    }
}
