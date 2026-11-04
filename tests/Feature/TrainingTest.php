<?php

declare(strict_types=1);

use Beziworld\Ml\Enums\Task;
use Beziworld\Ml\Events\ModelPromoted;
use Beziworld\Ml\Events\ModelTrained;
use Beziworld\Ml\Exceptions\InvalidModelDefinition;
use Beziworld\Ml\Facades\Ml;
use Beziworld\Ml\Models\Estimators;
use Beziworld\Ml\Tests\Fixtures\Customer;
use Beziworld\Ml\Tests\Fixtures\Models\ChurnModel;
use Beziworld\Ml\Tests\Fixtures\Models\CustomerSegments;
use Beziworld\Ml\Tests\Fixtures\Models\HousePriceModel;
use Beziworld\Ml\Tests\Fixtures\Models\OrderAnomalies;
use Beziworld\Ml\Tests\Fixtures\Models\SimilarProducts;
use Beziworld\Ml\Tests\Fixtures\Models\SpamFilter;
use Beziworld\Ml\Tests\Fixtures\Synthetic;
use Beziworld\Ml\Training\Validation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Rubix\ML\Classifiers\KNearestNeighbors;

beforeEach(function (): void {
    $this->createCustomersTable();

    foreach (Synthetic::customers() as $row) {
        Customer::query()->create($row);
    }
});

it('trains a classifier from an Eloquent query and stores version v1', function (): void {
    $report = Ml::train(ChurnModel::class);

    expect($report->model)->toBe('churn-model')
        ->and($report->version)->toBe('v1')
        ->and($report->task)->toBe('classifier')
        ->and($report->samples)->toBe(120)
        ->and($report->testSamples)->toBeGreaterThan(20)
        ->and($report->metric('accuracy'))->toBeGreaterThanOrEqual(0.9)
        ->and($report->metric('f1'))->toBeGreaterThanOrEqual(0.85)
        ->and($report->confusionMatrix)->toHaveKeys(['true', 'false'])
        ->and($report->promoted)->toBeTrue();

    Storage::disk('local')->assertExists('ml-models/churn-model/v1/model.bin');
    Storage::disk('local')->assertExists('ml-models/churn-model/v1/metadata.json');
    expect(Ml::repository()->activeVersion('churn-model'))->toBe('v1');
});

it('runs k-fold cross validation over every sample', function (): void {
    $report = Ml::train(ChurnModel::class, validation: Validation::kFold(4));

    expect($report->validation)->toBe('4-fold')
        ->and($report->testSamples)->toBe(120)
        ->and($report->metric('accuracy'))->toBeGreaterThanOrEqual(0.85);
});

it('trains a regressor with low error', function (): void {
    $report = Ml::train(HousePriceModel::class);

    expect($report->metric('r2'))->toBeGreaterThan(0.95)
        ->and($report->metric('mae'))->toBeLessThan(20000.0)
        ->and($report->metric('rmse'))->toBeGreaterThanOrEqual($report->metric('mae'));
});

it('clusters well separated customer segments', function (): void {
    $report = Ml::train(CustomerSegments::class);

    expect($report->validation)->toBe('none')
        ->and($report->metric('clusters'))->toBe(3.0)
        ->and($report->metric('silhouette'))->toBeGreaterThan(0.7)
        ->and($report->metric('v_measure'))->toBeGreaterThan(0.9);
});

it('detects anomalous orders', function (): void {
    $report = Ml::train(OrderAnomalies::class);

    expect($report->metric('anomaly_rate'))->toBeLessThan(0.15)
        ->and($report->metric('recall'))->toBeGreaterThanOrEqual(0.8);

    expect(Ml::predict(OrderAnomalies::class, ['amount' => 9000.0, 'items' => 60])->anomaly)->toBeTrue()
        ->and(Ml::predict(OrderAnomalies::class, ['amount' => 99.0, 'items' => 2])->anomaly)->toBeFalse();
});

it('trains a TF-IDF text classifier for spam', function (): void {
    $report = Ml::train(SpamFilter::class, validation: Validation::none());

    expect($report->metrics)->toBe([]);

    expect(Ml::predict(SpamFilter::class, ['message' => 'claim your free cash prize now'])->value)->toBe('spam')
        ->and(Ml::predict(SpamFilter::class, ['message' => 'can you review the meeting notes'])->value)->toBe('ham');
});

it('builds a recommender index', function (): void {
    $report = Ml::train(SimilarProducts::class);

    expect($report->task)->toBe(Task::Recommender->value)
        ->and($report->metric('items'))->toBe(8.0);

    $keys = Ml::recommend(SimilarProducts::class, Synthetic::products()[0])->pluck('key')->all();

    expect($keys)->toHaveCount(2)
        ->and($keys)->not->toContain(1)
        ->and(array_intersect($keys, [2, 3]))->toHaveCount(2);
});

it('trains ad-hoc models through the fluent builder', function (): void {
    $report = Ml::model('lead-scoring')
        ->classifier(new KNearestNeighbors(3))
        ->features(['logins' => 'numeric', 'monthly_spend' => 'numeric'])
        ->label('churned')
        ->using(Ml::dataset()->fromQuery(Customer::query()))
        ->train();

    expect($report->model)->toBe('lead-scoring')
        ->and($report->metric('accuracy'))->toBeGreaterThan(0.8);

    expect(Ml::predict('lead-scoring', ['logins' => 2, 'monthly_spend' => 50])->value)->toBe('true');
});

it('produces the same metrics for the same seed', function (): void {
    $model = fn() => Ml::model('seeded')
        ->classifier('random_forest')
        ->features(['plan', 'age', 'logins'])
        ->label('churned')
        ->using(Ml::dataset()->fromQuery(Customer::query()))
        ->seed(7);

    $first = $model()->train();
    $second = $model()->train();

    expect($first->metrics)->toBe($second->metrics)
        ->and($second->version)->toBe('v2');
});

it('trains a multilayer perceptron', function (): void {
    $report = Ml::model('mlp')
        ->classifier(fn() => Estimators::neuralNetwork([8], epochs: 50))
        ->features(['logins' => 'numeric', 'age' => 'numeric'])
        ->label('churned')
        ->using(Ml::dataset()->fromQuery(Customer::query()))
        ->train();

    expect($report->metric('accuracy'))->toBeGreaterThan(0.7);
});

it('rejects an estimator that does not match the task', function (): void {
    Ml::model('broken')
        ->regressor(new KNearestNeighbors())
        ->features(['logins'])
        ->label('monthly_spend')
        ->using(Ml::dataset()->fromQuery(Customer::query()))
        ->train();
})->throws(InvalidModelDefinition::class, 'the estimator is a classifier but the task is regressor');

it('requires a label for supervised models', function (): void {
    Ml::model('unlabeled')
        ->classifier()
        ->features(['logins'])
        ->using(Ml::dataset()->fromQuery(Customer::query()))
        ->train();
})->throws(InvalidModelDefinition::class, 'needs a label');

it('dispatches training and promotion events', function (): void {
    Event::fake([ModelTrained::class, ModelPromoted::class]);

    Ml::train(HousePriceModel::class);

    Event::assertDispatched(ModelTrained::class, fn(ModelTrained $event): bool => $event->metadata->version === 'v1' && $event->metadata->active);
    Event::assertDispatched(ModelPromoted::class, fn(ModelPromoted $event): bool => $event->version === 'v1' && $event->previous === null);
});

it('keeps the active version when auto promotion is disabled', function (): void {
    config()->set('ml.auto_promote', false);

    Ml::train(HousePriceModel::class);
    $second = Ml::train(HousePriceModel::class);

    expect($second->promoted)->toBeFalse()
        ->and(Ml::repository()->activeVersion('house-price-model'))->toBe('v1');
});
