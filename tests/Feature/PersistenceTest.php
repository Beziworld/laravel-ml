<?php

declare(strict_types=1);

use Beziworld\Ml\Exceptions\IntegrityCheckFailed;
use Beziworld\Ml\Exceptions\InvalidModelDefinition;
use Beziworld\Ml\Exceptions\ModelNotFound;
use Beziworld\Ml\Facades\Ml;
use Beziworld\Ml\Persistence\ModelRepository;
use Beziworld\Ml\Tests\Fixtures\Models\HousePriceModel;
use Illuminate\Support\Facades\Storage;

it('increments versions and writes complete metadata', function (): void {
    Ml::train(HousePriceModel::class);
    Ml::train(HousePriceModel::class);

    $versions = Ml::versions(HousePriceModel::class);
    $metadata = json_decode((string) Storage::disk('local')->get('ml-models/house-price-model/v2/metadata.json'), true);

    expect(array_map(fn($v) => $v->version, $versions))->toBe(['v1', 'v2'])
        ->and($versions[1]->active)->toBeTrue()
        ->and($metadata['features'])->toBe([['name' => 'area', 'type' => 'numeric'], ['name' => 'rooms', 'type' => 'numeric']])
        ->and($metadata['label'])->toBe('price')
        ->and($metadata['task'])->toBe('regressor')
        ->and($metadata['estimator'])->toBe(\Rubix\ML\Regressors\Ridge::class)
        ->and($metadata['dataset_hash'])->toHaveLength(64)
        ->and($metadata['checksum'])->toBe(hash('sha256', (string) Storage::disk('local')->get('ml-models/house-price-model/v2/model.bin')))
        ->and($metadata['signature'])->toHaveLength(64)
        ->and($metadata['rubix_version'])->toStartWith('3.')
        ->and($metadata['metrics'])->toHaveKeys(['mae', 'rmse', 'r2'])
        ->and($metadata['statistics'][0]['name'])->toBe('area')
        ->and($metadata['classes'])->toContain(\Beziworld\Ml\Models\TrainedModel::class);
});

it('promotes, rolls back and prunes versions', function (): void {
    foreach (range(1, 4) as $run) {
        Ml::train(HousePriceModel::class);
    }

    expect(Ml::promote(HousePriceModel::class, 'v2')->active)->toBeTrue()
        ->and(Ml::rollback(HousePriceModel::class)->version)->toBe('v1')
        ->and(Ml::prune(HousePriceModel::class, keep: 2))->toBe(['v2'])
        ->and(array_map(fn($v) => $v->version, Ml::versions(HousePriceModel::class)))->toBe(['v1', 'v3', 'v4']);

    Storage::disk('local')->assertMissing('ml-models/house-price-model/v2/model.bin');
});

it('never deletes the active version', function (): void {
    Ml::train(HousePriceModel::class);

    app(ModelRepository::class)->delete('house-price-model', 'v1');
})->throws(InvalidModelDefinition::class, 'is active');

it('refuses to load a tampered model file', function (): void {
    Ml::train(HousePriceModel::class);
    app(ModelRepository::class)->forgetLoaded();

    $path = 'ml-models/house-price-model/v1/model.bin';
    Storage::disk('local')->put($path, gzencode('O:8:"stdClass":0:{}'));

    Ml::predict(HousePriceModel::class, ['area' => 50, 'rooms' => 2]);
})->throws(IntegrityCheckFailed::class, 'checksum');

it('reports missing models and versions', function (): void {
    expect(fn() => Ml::predict(HousePriceModel::class, ['area' => 1, 'rooms' => 1]))->toThrow(ModelNotFound::class, 'no active version');

    Ml::train(HousePriceModel::class);

    expect(fn() => Ml::promote(HousePriceModel::class, 'v9'))->toThrow(ModelNotFound::class)
        ->and(fn() => Ml::promote(HousePriceModel::class, '../v1'))->toThrow(ModelNotFound::class)
        ->and(fn() => app(ModelRepository::class)->versions('../etc'))->toThrow(InvalidModelDefinition::class);
});

it('lists stored models and caches loaded versions', function (): void {
    Ml::train(HousePriceModel::class);
    $repository = app(ModelRepository::class);

    [$first] = $repository->load('house-price-model');
    [$second] = $repository->load('house-price-model', 'v1');

    expect($repository->models())->toBe(['house-price-model'])
        ->and($first)->toBe($second);
});
