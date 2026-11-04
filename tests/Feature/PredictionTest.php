<?php

declare(strict_types=1);

use Beziworld\Ml\Data\Prediction;
use Beziworld\Ml\Events\PredictionMade;
use Beziworld\Ml\Exceptions\InvalidModelDefinition;
use Beziworld\Ml\Facades\Ml;
use Beziworld\Ml\Models\ClassificationModel;
use Beziworld\Ml\Tests\Fixtures\Customer;
use Beziworld\Ml\Tests\Fixtures\Models\ChurnModel;
use Beziworld\Ml\Tests\Fixtures\Models\CustomerSegments;
use Beziworld\Ml\Tests\Fixtures\Models\HousePriceModel;
use Beziworld\Ml\Tests\Fixtures\Models\SimilarProducts;
use Beziworld\Ml\Tests\Fixtures\Synthetic;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->createCustomersTable();

    foreach (Synthetic::customers() as $row) {
        Customer::query()->create($row);
    }

    Ml::train(ChurnModel::class);
});

it('predicts a class with probabilities for an Eloquent model', function (): void {
    $customer = Customer::query()->where('logins', '<', 3)->firstOrFail();

    $prediction = $customer->predict(ChurnModel::class);

    expect($prediction)->toBeInstanceOf(Prediction::class)
        ->and($prediction->model)->toBe('churn-model')
        ->and($prediction->version)->toBe('v1')
        ->and($prediction->value)->toBe('true')
        ->and($prediction->is(true))->toBeTrue()
        ->and($prediction->probabilities)->toHaveKeys(['true', 'false'])
        ->and($prediction->confidence)->toBeGreaterThanOrEqual(0.5)
        ->and($customer->probabilities(ChurnModel::class)['true'])->toBe($prediction->probability(true));
});

it('predicts many subjects at once', function (): void {
    $predictions = Ml::predictMany(ChurnModel::class, [
        ['plan' => 'pro', 'age' => 30, 'monthly_spend' => 40, 'logins' => 1, 'signed_up_at' => '2025-03-01'],
        ['plan' => 'pro', 'age' => 30, 'monthly_spend' => 40, 'logins' => 25, 'signed_up_at' => '2025-03-01'],
    ]);

    expect($predictions->map(fn(Prediction $p) => $p->value)->all())->toBe(['true', 'false'])
        ->and(Ml::predictMany(ChurnModel::class, []))->toBeEmpty();
});

it('stores predictions on the model with the AsPrediction cast', function (): void {
    $customer = Customer::query()->firstOrFail();
    $customer->churn_prediction = $customer->predict(ChurnModel::class);
    $customer->save();

    $fresh = Customer::query()->findOrFail($customer->id);

    expect($fresh->churn_prediction)->toBeInstanceOf(Prediction::class)
        ->and($fresh->churn_prediction?->value)->toBe($customer->predict(ChurnModel::class)->value)
        ->and($fresh->getRawOriginal('churn_prediction'))->toContain('"model":"churn-model"');
});

it('predicts regression values and cluster numbers', function (): void {
    Ml::train(HousePriceModel::class);
    Ml::train(CustomerSegments::class);

    $price = Ml::predict(HousePriceModel::class, ['area' => 100, 'rooms' => 3])->value;
    $a = Ml::predict(CustomerSegments::class, ['recency' => 0.1, 'frequency' => 0.2]);
    $b = Ml::predict(CustomerSegments::class, ['recency' => 9.9, 'frequency' => 10.1]);

    expect($price)->toBeFloat()->toBeGreaterThan(300000.0)->toBeLessThan(370000.0)
        ->and($a->value)->toBeInt()
        ->and($a->value)->not->toBe($b->value);
});

it('dispatches sampled PredictionMade events when enabled', function (): void {
    Event::fake([PredictionMade::class]);
    config()->set('ml.events.predictions', true);
    $this->app->forgetInstance(\Beziworld\Ml\MlConfig::class);
    $this->app->forgetInstance(\Beziworld\Ml\Prediction\Predictor::class);
    $this->app->forgetInstance(\Beziworld\Ml\MlManager::class);
    Ml::clearResolvedInstances();

    Customer::query()->firstOrFail()->predict(ChurnModel::class);

    Event::assertDispatched(PredictionMade::class, fn(PredictionMade $event): bool => $event->prediction->model === 'churn-model' && $event->features['plan'] === 'basic');
});

it('does not dispatch prediction events by default', function (): void {
    Event::fake([PredictionMade::class]);

    Customer::query()->firstOrFail()->predict(ChurnModel::class);

    Event::assertNotDispatched(PredictionMade::class);
});

it('refuses predictions when the features changed since training', function (): void {
    $changed = new class extends ClassificationModel {
        public function name(): string
        {
            return 'churn-model';
        }

        public function features(): array
        {
            return ['plan', 'age'];
        }
    };

    Ml::predict($changed, ['plan' => 'pro', 'age' => 40]);
})->throws(InvalidModelDefinition::class, 'the features changed since v1 was trained');

it('predicts with a stored model by its name', function (): void {
    $prediction = Ml::predict('churn-model', ['plan' => 'pro', 'age' => 30, 'monthly_spend' => 40, 'logins' => 0, 'signed_up_at' => '2025-03-01']);

    expect($prediction->value)->toBe('true');
});

it('recommends similar items and leaves the item itself out', function (): void {
    Ml::train(SimilarProducts::class);

    $phone = Synthetic::products()[3];
    $recommendations = Ml::recommend(SimilarProducts::class, $phone, 3);

    expect($recommendations)->toHaveCount(3)
        ->and($recommendations->pluck('key')->take(2)->sort()->values()->all())->toBe([5, 8])
        ->and($recommendations->pluck('key')->all())->not->toContain(4)
        ->and($recommendations->first()?->similarity)->toBeGreaterThan(0.0)
        ->and(fn() => Ml::predict(SimilarProducts::class, $phone))->toThrow(InvalidModelDefinition::class, 'recommend');
});
