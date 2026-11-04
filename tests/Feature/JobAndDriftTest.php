<?php

declare(strict_types=1);

use Beziworld\Ml\Exceptions\InvalidModelDefinition;
use Beziworld\Ml\Facades\Ml;
use Beziworld\Ml\Jobs\TrainModel;
use Beziworld\Ml\Tests\Fixtures\Models\HousePriceModel;
use Beziworld\Ml\Tests\Fixtures\Models\SpamFilter;
use Beziworld\Ml\Tests\Fixtures\Synthetic;
use Beziworld\Ml\Training\Validation;
use Illuminate\Support\Facades\Queue;

it('runs the queued job synchronously', function (): void {
    config()->set('ml.training.time_limit', 900);

    $job = new TrainModel(HousePriceModel::class, Validation::KFOLD, folds: 4);
    $report = app()->call([$job, 'handle']);

    expect($job->timeout)->toBe(900)
        ->and($job->tries)->toBe(1)
        ->and($job->tags())->toBe(['ml', 'ml:' . HousePriceModel::class])
        ->and($report->validation)->toBe('4-fold')
        ->and(Ml::repository()->activeVersion('house-price-model'))->toBe('v1');
});

it('dispatches the job through the facade', function (): void {
    Queue::fake();

    Ml::queue(HousePriceModel::class, Validation::none(), promote: false);

    Queue::assertPushed(TrainModel::class, fn(TrainModel $job): bool => $job->promote === false && $job->strategy === 'none');
});

it('does not queue ad-hoc models', function (): void {
    Queue::fake();

    Ml::queue(Ml::model('adhoc')->features(['a'])->make()::class);
})->throws(InvalidModelDefinition::class);

it('finds no drift on the training data and drift on shifted data', function (): void {
    Ml::train(HousePriceModel::class);

    $same = Ml::drift(HousePriceModel::class, Synthetic::houses());
    $shifted = Ml::drift(HousePriceModel::class, array_map(fn(array $house) => ['area' => $house['area'] + 200, 'rooms' => null], Synthetic::houses()));

    expect($same->drifted())->toBeFalse()
        ->and($same->feature('area')?->meanShift)->toBe(0.0)
        ->and($shifted->drifted())->toBeTrue()
        ->and($shifted->driftedFeatures())->toBe(['area', 'rooms'])
        ->and($shifted->feature('rooms')?->missingShift)->toBe(1.0);
});

it('detects a shift in categories and text length', function (): void {
    Ml::train(SpamFilter::class, validation: Validation::none());

    $report = Ml::drift(SpamFilter::class, [['message' => str_repeat('a much longer message than before ', 5)]]);

    expect($report->feature('message')?->drifted)->toBeTrue()
        ->and($report->samples)->toBe(1);

    $categories = Ml::model('plans')->classifier('tree')->features(['plan' => 'categorical'])->label('y')
        ->using(Ml::dataset()->fromCollection([['plan' => 'a', 'y' => '1'], ['plan' => 'b', 'y' => '0'], ['plan' => 'a', 'y' => '1'], ['plan' => 'b', 'y' => '0']]))
        ->validation(Validation::none());
    $categories->train();

    $drift = Ml::drift($categories->make(), [['plan' => 'c'], ['plan' => 'c'], ['plan' => 'a']]);

    expect($drift->feature('plan')?->distributionShift)->toBeGreaterThan(0.6)
        ->and($drift->drifted())->toBeTrue();
});
