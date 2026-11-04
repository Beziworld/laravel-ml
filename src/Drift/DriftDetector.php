<?php

declare(strict_types=1);

namespace Beziworld\Ml\Drift;

use Beziworld\Ml\Data\DriftReport;
use Beziworld\Ml\Data\FeatureDrift;
use Beziworld\Ml\Data\FeatureStatistics;
use Beziworld\Ml\Data\ModelMetadata;
use Beziworld\Ml\Dataset\Dataset;
use Beziworld\Ml\Enums\FeatureType;
use Beziworld\Ml\MlConfig;

/**
 * Simple data drift check: compares feature statistics of new data with the statistics
 * recorded when the model version was trained.
 *
 * Continuous features drift when the mean moves by more than ml.drift.mean_shift training
 * standard deviations; categorical features when the total variation distance of category
 * shares exceeds ml.drift.distribution_shift; text features when the average length changes
 * by more than mean_shift (relative); any feature when the missing rate changes by more
 * than ml.drift.missing_shift.
 */
final readonly class DriftDetector
{
    public function __construct(private MlConfig $config) {}

    public function compare(ModelMetadata $metadata, Dataset $dataset): DriftReport
    {
        $features = [];

        foreach ($dataset->statistics() as $current) {
            $baseline = $metadata->statistic($current->name);

            if ($baseline === null) {
                continue;
            }

            $features[] = $this->feature($baseline, $current);
        }

        return new DriftReport($metadata->name, $metadata->version, $dataset->count(), $features);
    }

    private function feature(FeatureStatistics $baseline, FeatureStatistics $current): FeatureDrift
    {
        $type = FeatureType::tryFrom($baseline->type) ?? FeatureType::Categorical;
        $missingShift = round(abs($current->missingRate() - $baseline->missingRate()), 6);
        $meanShift = null;
        $distributionShift = null;

        if ($type->isContinuous() && $baseline->mean !== null && $current->mean !== null) {
            $scale = $baseline->std !== null && $baseline->std > 1e-12 ? $baseline->std : max(abs($baseline->mean), 1.0);
            $meanShift = round(abs($current->mean - $baseline->mean) / $scale, 6);
        } elseif ($type === FeatureType::Text && $baseline->averageLength !== null && $current->averageLength !== null) {
            $meanShift = round(abs($current->averageLength - $baseline->averageLength) / max($baseline->averageLength, 1.0), 6);
        } elseif ($type === FeatureType::Categorical) {
            $distributionShift = round($this->totalVariation($baseline->distribution, $current->distribution), 6);
        }

        $drifted = $missingShift > $this->config->driftMissingShift
            || ($meanShift !== null && $meanShift > $this->config->driftMeanShift)
            || ($distributionShift !== null && $distributionShift > $this->config->driftDistributionShift);

        return new FeatureDrift($current->name, $type->value, $drifted, $missingShift, $meanShift, $distributionShift);
    }

    /**
     * @param array<string, float> $baseline
     * @param array<string, float> $current
     */
    private function totalVariation(array $baseline, array $current): float
    {
        if ($baseline === [] || $current === []) {
            return 0.0;
        }

        $distance = 0.0;

        foreach (array_unique([...array_map('strval', array_keys($baseline)), ...array_map('strval', array_keys($current))]) as $category) {
            $distance += abs(($baseline[$category] ?? 0.0) - ($current[$category] ?? 0.0));
        }

        return $distance / 2;
    }
}
