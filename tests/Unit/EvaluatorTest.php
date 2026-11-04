<?php

declare(strict_types=1);

use Beziworld\Ml\Exceptions\InvalidDataset;
use Beziworld\Ml\Training\Evaluator;
use Beziworld\Ml\Training\Splitter;
use Beziworld\Ml\Training\Validation;

it('computes classification metrics and an actual-by-predicted confusion matrix', function (): void {
    $result = Evaluator::classification(['a', 'b', 'a', 'a'], ['a', 'b', 'b', 'a']);

    expect($result['metrics']['accuracy'])->toBe(0.75)
        ->and($result['metrics']['f1'])->toBeGreaterThan(0.7)
        ->and($result['confusion_matrix'])->toBe(['a' => ['a' => 2, 'b' => 0], 'b' => ['a' => 1, 'b' => 1]]);
});

it('computes regression errors', function (): void {
    expect(Evaluator::regression([1.0, 2.0, 3.0], [1.0, 2.0, 3.0]))->toBe(['mae' => 0.0, 'rmse' => 0.0, 'r2' => 1.0])
        ->and(Evaluator::regression([2.0, 2.0], [1.0, 3.0]))->toBe(['mae' => 1.0, 'rmse' => 1.0, 'r2' => 0.0]);
});

it('scores separated clusters with a high silhouette and ignores noise', function (): void {
    $samples = [[0.0, 0.0], [0.1, 0.0], [10.0, 10.0], [10.1, 10.0], [50.0, 50.0]];

    expect(Evaluator::silhouette($samples, [0, 0, 1, 1, -1]))->toBeGreaterThan(0.95)
        ->and(Evaluator::silhouette($samples, [0, 0, 0, 0, 0]))->toBe(0.0)
        ->and(Evaluator::clustering([0, 0, 1, 1], [[0.0], [0.1], [5.0], [5.1]], ['x', 'x', 'y', 'y'])['v_measure'])->toBe(1.0);
});

it('measures anomaly detection against known labels', function (): void {
    $metrics = Evaluator::anomaly([0, 1, 1, 0], [0, 1, 0, 0]);

    expect($metrics)->toBe(['anomaly_rate' => 0.5, 'accuracy' => 0.75, 'precision' => 0.5, 'recall' => 1.0, 'f1' => 0.666667]);
});

it('creates stratified holdout splits', function (): void {
    [$train, $test] = Splitter::holdout(10, 0.2, ['a', 'a', 'a', 'a', 'a', 'b', 'b', 'b', 'b', 'b']);

    expect($test)->toHaveCount(2)
        ->and(array_values(array_intersect($test, [0, 1, 2, 3, 4])))->toHaveCount(1)
        ->and(array_merge($train, $test))->toHaveCount(10);
});

it('creates disjoint k-fold splits covering every sample', function (): void {
    $splits = Splitter::kFold(9, 3);
    $tested = array_merge(...array_map(fn(array $split): array => $split[1], $splits));
    sort($tested);

    expect($splits)->toHaveCount(3)
        ->and($tested)->toBe(range(0, 8))
        ->and(array_intersect($splits[0][0], $splits[0][1]))->toBe([])
        ->and(fn() => Splitter::kFold(2, 3))->toThrow(InvalidDataset::class);
});

it('validates the cross validation settings', function (): void {
    expect(Validation::kFold(3)->describe())->toBe('3-fold')
        ->and(Validation::holdout(0.25)->describe())->toBe('holdout (25% test)')
        ->and(fn() => new Validation('loo'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => Validation::kFold(1))->toThrow(InvalidArgumentException::class);
});
