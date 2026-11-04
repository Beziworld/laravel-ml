<?php

declare(strict_types=1);

use Beziworld\Ml\Enums\FeatureType;
use Beziworld\Ml\Enums\Task;
use Beziworld\Ml\Exceptions\InvalidDataset;
use Beziworld\Ml\Facades\Ml;
use Beziworld\Ml\Tests\Fixtures\Customer;
use Beziworld\Ml\Tests\Fixtures\Synthetic;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;

it('reads a collection, infers types and keeps labels', function (): void {
    $dataset = Ml::dataset()
        ->fromCollection(collect(Synthetic::customers(10)), ['plan', 'age', 'signed_up_at'], 'churned')
        ->get();

    expect($dataset->count())->toBe(10)
        ->and($dataset->schema()->features[0]->type)->toBe(FeatureType::Categorical)
        ->and($dataset->schema()->features[1]->type)->toBe(FeatureType::Numeric)
        ->and($dataset->samples()[0][0])->toBe('basic')
        ->and($dataset->samples()[0][1])->toBe(20.0)
        ->and($dataset->labels()[0])->toBe('true')
        ->and($dataset->labelName())->toBe('churned');
});

it('streams Eloquent queries lazily and collects keys', function (): void {
    $this->createCustomersTable();

    foreach (Synthetic::customers(15) as $row) {
        Customer::query()->create($row);
    }

    $dataset = Ml::dataset()
        ->fromQuery(Customer::query()->where('plan', 'pro'), ['age' => 'numeric', 'signed_up_at' => 'date'], 'churned', chunk: 2)
        ->get();

    expect($dataset->count())->toBe(5)
        ->and($dataset->keys())->toBe([2, 5, 8, 11, 14])
        ->and($dataset->samples()[0][1])->toBeFloat();
});

it('accepts php-ml style arrays', function (): void {
    $dataset = Ml::dataset()->fromArray([[1, 3], [1, 4], [2, 4]], ['a', 'a', 'b'])->get();

    expect($dataset->schema()->names())->toBe(['f0', 'f1'])
        ->and($dataset->labels())->toBe(['a', 'a', 'b'])
        ->and($dataset->toRubix(Task::Classifier))->toBeInstanceOf(Labeled::class);
});

it('streams CSV files with a header row', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'ml');
    file_put_contents($path, "\u{FEFF}area;rooms;price\n50;2;150000\n70;3;210000\n\n90;;270000\n");

    $dataset = Ml::dataset()->fromCsv($path, ['area', 'rooms'], 'price', delimiter: ';')->get();
    unlink($path);

    expect($dataset->count())->toBe(3)
        ->and($dataset->samples()[0])->toBe([50.0, 2.0])
        ->and(is_nan($dataset->samples()[2][1]))->toBeTrue()
        ->and($dataset->labelsFor(Task::Regressor))->toBe([150000.0, 210000.0, 270000.0]);
});

it('limits the number of records', function (): void {
    expect(Ml::dataset()->fromCollection(Synthetic::houses(), ['area'])->limit(7)->get()->count())->toBe(7);
});

it('fails without a source or with null labels', function (): void {
    expect(fn() => Ml::dataset()->features(['a'])->get())->toThrow(InvalidDataset::class, 'no source')
        ->and(fn() => Ml::dataset()->fromCollection([['a' => 1, 'y' => null]], ['a'], 'y')->get())->toThrow(InvalidDataset::class, 'label is missing');
});

it('applies imputation, one-hot encoding and normalisation', function (): void {
    $rubix = Ml::dataset()
        ->fromCollection([
            ['plan' => 'basic', 'spend' => 10],
            ['plan' => 'pro', 'spend' => null],
            ['plan' => 'team', 'spend' => 30],
        ], ['plan' => 'categorical', 'spend' => 'numeric'])
        ->impute()
        ->oneHot()
        ->normalize('minmax')
        ->toRubix();

    expect($rubix)->toBeInstanceOf(Unlabeled::class)
        ->and($rubix->numFeatures())->toBe(4)
        ->and($rubix->sample(0))->toBe([0.0, 1.0, 0.0, 0.0])
        ->and($rubix->sample(1)[0])->toBe(0.5);
});

it('exposes statistics, a stable hash and named rows', function (): void {
    $build = fn() => Ml::dataset()->fromCollection(Synthetic::orders(4, 1), ['amount', 'items'], 'fraud')->get();
    $dataset = $build();
    $statistics = $dataset->statistics();

    expect($dataset->hash())->toBe($build()->hash())
        ->and($statistics[0]->name)->toBe('amount')
        ->and($statistics[0]->max)->toBe(5000.0)
        ->and($statistics[0]->missing)->toBe(0)
        ->and($dataset->toArray()[0])->toBe(['amount' => 90.0, 'items' => 1.0, 'fraud' => 'false'])
        ->and($dataset->labelsFor(Task::AnomalyDetector))->toBe([0, 0, 0, 0, 1]);
});
