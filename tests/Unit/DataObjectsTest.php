<?php

declare(strict_types=1);

use Beziworld\Ml\Data\DriftReport;
use Beziworld\Ml\Data\FeatureDrift;
use Beziworld\Ml\Data\FeatureStatistics;
use Beziworld\Ml\Data\ModelMetadata;
use Beziworld\Ml\Data\Prediction;
use Beziworld\Ml\Data\Recommendation;
use Beziworld\Ml\Data\TrainingReport;
use Carbon\CarbonImmutable;

it('serialises predictions to JSON and back', function (): void {
    $prediction = new Prediction('churn', 'v2', 'classifier', 'true', ['true' => 0.8, 'false' => 0.2], 0.8, predictedAt: CarbonImmutable::parse('2026-11-04T10:00:00+00:00'));
    $copy = Prediction::fromArray(json_decode((string) json_encode($prediction), true));

    expect($copy->is(true))->toBeTrue()
        ->and($copy->is('false', 'maybe'))->toBeFalse()
        ->and($copy->probability(true))->toBe(0.8)
        ->and($copy->toArray())->toBe($prediction->toArray())
        ->and($prediction->toArray())->not->toHaveKeys(['score', 'anomaly']);
});

it('restores training reports and metadata from arrays', function (): void {
    $report = TrainingReport::fromArray([
        'model' => 'm', 'version' => 'v3', 'task' => 'regressor', 'validation' => '5-fold', 'samples' => '100',
        'test_samples' => 100, 'metrics' => ['r2' => '0.9', 'bad' => 'x'], 'confusion_matrix' => null,
        'duration_ms' => 12.5, 'trained_at' => '2026-01-01T00:00:00+00:00', 'dataset_hash' => 'abc', 'promoted' => true,
    ]);

    $metadata = ModelMetadata::fromArray([
        'name' => 'm', 'version' => 'v12', 'task' => 'classifier', 'features' => [['name' => 'age', 'type' => 'numeric']],
        'statistics' => [['name' => 'age', 'type' => 'numeric', 'count' => 10, 'missing' => 2, 'mean' => 30]],
        'metrics' => ['accuracy' => 0.9], 'trained_at' => '2026-01-01T00:00:00+00:00', 'classes' => ['A', 3],
    ], active: true);

    expect($report->samples)->toBe(100)
        ->and($report->metrics)->toBe(['r2' => 0.9])
        ->and($report->toArray()['trained_at'])->toBe('2026-01-01T00:00:00+00:00')
        ->and($metadata->versionNumber())->toBe(12)
        ->and($metadata->featureNames())->toBe(['age'])
        ->and($metadata->statistic('age')?->missingRate())->toBe(0.2)
        ->and($metadata->classes)->toBe(['A'])
        ->and($metadata->jsonSerialize()['active'])->toBeTrue();
});

it('summarises drift and recommendations', function (): void {
    $report = new DriftReport('m', 'v1', 10, [
        new FeatureDrift('age', 'numeric', true, 0.0, 1.2),
        new FeatureDrift('plan', 'categorical', false, 0.0, null, 0.05),
    ]);

    expect($report->drifted())->toBeTrue()
        ->and($report->driftedFeatures())->toBe(['age'])
        ->and($report->feature('plan')?->distributionShift)->toBe(0.05)
        ->and(Recommendation::fromDistance(7, 1.0)->toArray())->toBe(['key' => 7, 'distance' => 1.0, 'similarity' => 0.5])
        ->and((new FeatureStatistics('a', 'text', 4, 1, averageLength: 3.5))->toArray())->toBe(['name' => 'a', 'type' => 'text', 'count' => 4, 'missing' => 1, 'average_length' => 3.5]);
});
