<?php

declare(strict_types=1);

namespace Beziworld\Ml\Training;

use Rubix\ML\CrossValidation\Metrics\Completeness;
use Rubix\ML\CrossValidation\Metrics\Homogeneity;
use Rubix\ML\CrossValidation\Metrics\VMeasure;
use Rubix\ML\CrossValidation\Reports\MulticlassBreakdown;

/**
 * Computes the metrics stored in training and evaluation reports.
 */
final class Evaluator
{
    /** Maximum number of samples used for the O(n^2) silhouette score. */
    public const int SILHOUETTE_SAMPLES = 500;

    /**
     * @param list<mixed> $predictions
     * @param list<int|float|string> $labels
     * @return array{metrics: array<string, float>, confusion_matrix: array<string, array<string, int>>}
     */
    public static function classification(array $predictions, array $labels): array
    {
        $predictions = array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '', $predictions);
        $labels = array_map('strval', $labels);

        $overall = (new MulticlassBreakdown())->generate($predictions, $labels)->toArray()['overall'] ?? [];
        $metric = static fn(string $key): float => is_array($overall) && is_numeric($overall[$key] ?? null) ? round((float) $overall[$key], 6) : 0.0;

        return [
            'metrics' => [
                'accuracy' => $metric('accuracy'),
                'precision' => $metric('precision'),
                'recall' => $metric('recall'),
                'f1' => $metric('f1 score'),
                'mcc' => $metric('mcc'),
            ],
            'confusion_matrix' => self::confusionMatrix($predictions, $labels),
        ];
    }

    /**
     * @param list<string> $predictions
     * @param list<string> $labels
     * @return array<string, array<string, int>> actual => predicted => count
     */
    public static function confusionMatrix(array $predictions, array $labels): array
    {
        $classes = array_values(array_unique([...$labels, ...$predictions]));
        sort($classes);

        $matrix = [];

        foreach ($classes as $actual) {
            foreach ($classes as $predicted) {
                $matrix[$actual][$predicted] = 0;
            }
        }

        foreach ($labels as $offset => $actual) {
            $matrix[$actual][$predictions[$offset]]++;
        }

        return $matrix;
    }

    /**
     * @param list<mixed> $predictions
     * @param list<int|float|string> $labels
     * @return array<string, float>
     */
    public static function regression(array $predictions, array $labels): array
    {
        $n = count($labels);

        if ($n === 0) {
            return ['mae' => 0.0, 'rmse' => 0.0, 'r2' => 0.0];
        }

        $absolute = 0.0;
        $squared = 0.0;
        $mean = array_sum(array_map('floatval', $labels)) / $n;
        $total = 0.0;

        foreach ($labels as $offset => $label) {
            $prediction = is_numeric($predictions[$offset] ?? null) ? (float) $predictions[$offset] : 0.0;
            $error = (float) $label - $prediction;
            $absolute += abs($error);
            $squared += $error ** 2;
            $total += ((float) $label - $mean) ** 2;
        }

        return [
            'mae' => round($absolute / $n, 6),
            'rmse' => round(sqrt($squared / $n), 6),
            'r2' => round($total > 0.0 ? 1.0 - $squared / $total : 0.0, 6),
        ];
    }

    /**
     * @param list<mixed> $clusters
     * @param list<list<mixed>> $samples transformed (numeric) samples
     * @param list<int|float|string>|null $labels optional ground truth
     * @return array<string, float>
     */
    public static function clustering(array $clusters, array $samples, ?array $labels = null): array
    {
        $assigned = array_filter($clusters, static fn(mixed $cluster): bool => $cluster !== -1);

        $metrics = [
            'silhouette' => round(self::silhouette($samples, $clusters), 6),
            'clusters' => (float) count(array_unique(array_map(static fn(mixed $c): string => is_scalar($c) ? (string) $c : '', $assigned))),
        ];

        if ($labels !== null && $labels !== []) {
            $labels = array_map('strval', $labels);
            $metrics['v_measure'] = round((new VMeasure())->score($clusters, $labels), 6);
            $metrics['homogeneity'] = round((new Homogeneity())->score($clusters, $labels), 6);
            $metrics['completeness'] = round((new Completeness())->score($clusters, $labels), 6);
        }

        return $metrics;
    }

    /**
     * @param list<mixed> $predictions 0/1 anomaly flags
     * @param list<int|float|string>|null $labels optional 0/1 ground truth
     * @return array<string, float>
     */
    public static function anomaly(array $predictions, ?array $labels = null): array
    {
        $flags = array_map(static fn(mixed $value): int => (int) (is_numeric($value) ? $value : 0), $predictions);
        $metrics = ['anomaly_rate' => round($flags === [] ? 0.0 : array_sum($flags) / count($flags), 6)];

        if ($labels !== null && $labels !== []) {
            $truth = array_map(static fn(int|float|string $label): int => (int) $label, $labels);
            $tp = $fp = $fn = $correct = 0;

            foreach ($truth as $offset => $actual) {
                $predicted = $flags[$offset] ?? 0;
                $correct += $predicted === $actual ? 1 : 0;
                $tp += $predicted === 1 && $actual === 1 ? 1 : 0;
                $fp += $predicted === 1 && $actual === 0 ? 1 : 0;
                $fn += $predicted === 0 && $actual === 1 ? 1 : 0;
            }

            $precision = $tp + $fp > 0 ? $tp / ($tp + $fp) : 0.0;
            $recall = $tp + $fn > 0 ? $tp / ($tp + $fn) : 0.0;

            $metrics += [
                'accuracy' => round($correct / count($truth), 6),
                'precision' => round($precision, 6),
                'recall' => round($recall, 6),
                'f1' => round($precision + $recall > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0, 6),
            ];
        }

        return $metrics;
    }

    /**
     * Mean silhouette coefficient (-1..1, higher means better separated clusters).
     *
     * Noise points (cluster -1) are ignored. Large datasets are sampled at an even stride so the
     * result does not depend on the random number generator.
     *
     * @param list<list<mixed>> $samples
     * @param list<mixed> $clusters
     */
    public static function silhouette(array $samples, array $clusters, int $max = self::SILHOUETTE_SAMPLES): float
    {
        $points = [];

        foreach ($samples as $offset => $sample) {
            $cluster = $clusters[$offset] ?? -1;

            if ($cluster === -1 || ! is_scalar($cluster)) {
                continue;
            }

            // The "c" prefix keeps cluster ids as string array keys (numeric strings would become integers).
            $points[] = [array_map(static fn(mixed $v): float => is_numeric($v) ? (float) $v : 0.0, $sample), 'c' . $cluster];
        }

        $n = count($points);

        if ($n > $max) {
            $step = $n / $max;
            $sampled = [];

            for ($i = 0; $i < $max; $i++) {
                $sampled[] = $points[(int) floor($i * $step)];
            }

            $points = $sampled;
            $n = $max;
        }

        $labels = array_unique(array_column($points, 1));

        if ($n < 2 || count($labels) < 2) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($points as $i => [$vector, $cluster]) {
            $sums = [];
            $counts = [];

            foreach ($points as $j => [$other, $otherCluster]) {
                if ($i === $j) {
                    continue;
                }

                $distance = 0.0;

                foreach ($vector as $k => $value) {
                    $distance += ($value - ($other[$k] ?? 0.0)) ** 2;
                }

                $sums[$otherCluster] = ($sums[$otherCluster] ?? 0.0) + sqrt($distance);
                $counts[$otherCluster] = ($counts[$otherCluster] ?? 0) + 1;
            }

            if (! isset($counts[$cluster])) {
                continue;
            }

            $a = $sums[$cluster] / $counts[$cluster];
            $b = INF;

            foreach ($sums as $otherCluster => $sum) {
                if ($otherCluster !== $cluster) {
                    $b = min($b, $sum / $counts[$otherCluster]);
                }
            }

            $denominator = max($a, $b);
            $total += $denominator > 0.0 && is_finite($b) ? ($b - $a) / $denominator : 0.0;
        }

        return $total / $n;
    }
}
