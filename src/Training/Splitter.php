<?php

declare(strict_types=1);

namespace Beziworld\Ml\Training;

use Beziworld\Ml\Exceptions\InvalidDataset;

/**
 * Produces (optionally stratified) train/test offset splits for holdout and k-fold validation.
 *
 * Shuffling uses PHP's global Mersenne Twister, so mt_srand() makes splits reproducible.
 */
final class Splitter
{
    /**
     * @param list<int|float|string>|null $labels labels to stratify by (classification)
     * @return array{0: list<int>, 1: list<int>} [train offsets, test offsets]
     */
    public static function holdout(int $samples, float $ratio, ?array $labels = null): array
    {
        if ($samples < 2) {
            throw InvalidDataset::because('holdout validation needs at least 2 samples.');
        }

        $train = [];
        $test = [];

        foreach (self::strata($samples, $labels) as $offsets) {
            shuffle($offsets);
            $size = count($offsets);
            $testSize = $size > 1 ? max(1, (int) round($size * $ratio)) : 0;

            array_push($test, ...array_slice($offsets, 0, $testSize));
            array_push($train, ...array_slice($offsets, $testSize));
        }

        if ($test === []) {
            $test[] = array_pop($train) ?? 0;
        }

        sort($train);
        sort($test);

        return [$train, $test];
    }

    /**
     * @param list<int|float|string>|null $labels
     * @return list<array{0: list<int>, 1: list<int>}> one [train, test] pair per fold
     */
    public static function kFold(int $samples, int $folds, ?array $labels = null): array
    {
        if ($samples < $folds) {
            throw InvalidDataset::because(sprintf('%d-fold validation needs at least %d samples, %d given.', $folds, $folds, $samples));
        }

        $buckets = array_fill(0, $folds, []);
        $next = 0;

        foreach (self::strata($samples, $labels) as $offsets) {
            shuffle($offsets);

            foreach ($offsets as $offset) {
                $buckets[$next % $folds][] = $offset;
                $next++;
            }
        }

        $splits = [];

        foreach ($buckets as $index => $test) {
            $train = [];

            foreach ($buckets as $other => $offsets) {
                if ($other !== $index) {
                    array_push($train, ...$offsets);
                }
            }

            sort($train);
            sort($test);
            $splits[] = [$train, $test];
        }

        return $splits;
    }

    /**
     * @param list<int|float|string>|null $labels
     * @return list<list<int>>
     */
    private static function strata(int $samples, ?array $labels): array
    {
        if ($labels === null) {
            return [range(0, $samples - 1)];
        }

        $groups = [];

        foreach ($labels as $offset => $label) {
            $groups['k' . $label][] = $offset;
        }

        ksort($groups);

        return array_values($groups);
    }
}
