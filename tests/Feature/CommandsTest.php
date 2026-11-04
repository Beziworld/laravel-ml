<?php

declare(strict_types=1);

use Beziworld\Ml\Facades\Ml;
use Beziworld\Ml\Jobs\TrainModel;
use Beziworld\Ml\Tests\Fixtures\Models\ChurnModel;
use Beziworld\Ml\Tests\Fixtures\Models\HousePriceModel;
use Beziworld\Ml\Tests\Fixtures\Models\OrderAnomalies;
use Beziworld\Ml\Tests\Fixtures\Synthetic;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('ml.models', ['houses' => HousePriceModel::class, 'orders' => OrderAnomalies::class]);
    OrderAnomalies::$records = null;
});

it('trains a model from the console', function (): void {
    $this->artisan('ml:train', ['model' => 'houses', '--folds' => 3])
        ->expectsOutputToContain('house-price-model v1 trained on 80 samples')
        ->assertSuccessful();

    expect(Ml::repository()->activeVersion('house-price-model'))->toBe('v1')
        ->and(Ml::versions('houses')[0]->validation)->toBe('3-fold');
});

it('queues training on the configured queue', function (): void {
    Queue::fake();
    config()->set('ml.queue.name', 'ml-training');

    $this->artisan('ml:train', ['model' => HousePriceModel::class, '--queue' => true, '--holdout' => '0.3'])
        ->expectsOutputToContain('queued on ml-training')
        ->assertSuccessful();

    Queue::assertPushedOn('ml-training', TrainModel::class, fn(TrainModel $job): bool => $job->model === HousePriceModel::class
        && $job->validation()?->strategy === 'holdout'
        && $job->validation()->ratio === 0.3);
});

it('lists models and versions with metrics', function (): void {
    $this->artisan('ml:models')->expectsOutputToContain('No models stored yet')->assertSuccessful();

    Ml::train(HousePriceModel::class);

    $this->artisan('ml:models')->expectsOutputToContain('house-price-model')->assertSuccessful();
    $this->artisan('ml:models', ['model' => 'houses'])->expectsOutputToContain('r2=')->assertSuccessful();
});

it('promotes, rolls back and prunes from the console', function (): void {
    Ml::train(HousePriceModel::class);
    Ml::train(HousePriceModel::class);
    Ml::train(HousePriceModel::class);

    $this->artisan('ml:promote', ['model' => 'houses', 'model_version' => 'v1'])->expectsOutputToContain('v1 is now active')->assertSuccessful();
    $this->artisan('ml:promote', ['model' => 'houses', 'model_version' => 'v3'])->assertSuccessful();
    $this->artisan('ml:rollback', ['model' => 'houses'])->expectsOutputToContain('rolled back to v2')->assertSuccessful();
    $this->artisan('ml:prune', ['--keep' => 1])->expectsOutputToContain('Pruned 1 version(s)')->assertSuccessful();

    expect(array_map(fn($v) => $v->version, Ml::versions('houses')))->toBe(['v2', 'v3']);
});

it('evaluates the active version with a confusion matrix', function (): void {
    $this->createCustomersTable();

    foreach (Synthetic::customers() as $row) {
        \Beziworld\Ml\Tests\Fixtures\Customer::query()->create($row);
    }

    Ml::train(ChurnModel::class);

    $this->artisan('ml:evaluate', ['model' => ChurnModel::class])
        ->expectsOutputToContain('evaluated on 120 samples')
        ->expectsOutputToContain('Confusion matrix')
        ->assertSuccessful();

    expect(Ml::evaluate(ChurnModel::class)->metric('accuracy'))->toBeGreaterThan(0.95);
});

it('fails the drift check when the data shifted', function (): void {
    Ml::train('orders');

    $this->artisan('ml:drift', ['model' => 'orders'])->expectsOutputToContain('No drift detected')->assertSuccessful();

    OrderAnomalies::$records = array_map(fn(array $order) => ['amount' => $order['amount'] * 3, 'items' => $order['items'], 'fraud' => false], Synthetic::orders());

    $this->artisan('ml:drift', ['model' => 'orders'])->expectsOutputToContain('Drift detected in: amount')->assertFailed();
});
