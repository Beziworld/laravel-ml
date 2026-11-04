<?php

declare(strict_types=1);

use Beziworld\Ml\Dataset\Feature;
use Beziworld\Ml\Dataset\FeatureSchema;
use Beziworld\Ml\Enums\FeatureType;
use Beziworld\Ml\Enums\Task;
use Beziworld\Ml\Exceptions\InvalidDataset;
use Carbon\CarbonImmutable;

it('casts numeric values and marks missing ones as NAN', function (): void {
    $feature = Feature::numeric('age');

    expect($feature->cast(42))->toBe(42.0)
        ->and($feature->cast('3.5'))->toBe(3.5)
        ->and($feature->cast(true))->toBe(1.0)
        ->and(is_nan($feature->cast(null)))->toBeTrue()
        ->and(is_nan($feature->cast('')))->toBeTrue();
});

it('casts booleans from strings and numbers', function (): void {
    $feature = Feature::boolean('active');

    expect($feature->cast('yes'))->toBe(1.0)
        ->and($feature->cast('off'))->toBe(0.0)
        ->and($feature->cast(0))->toBe(0.0)
        ->and($feature->cast(true))->toBe(1.0);
});

it('casts dates to days since the Unix epoch', function (): void {
    $feature = Feature::date('signed_up_at');

    expect($feature->cast(CarbonImmutable::parse('1970-01-11 00:00:00', 'UTC')))->toBe(10.0)
        ->and($feature->cast('1970-01-02T00:00:00+00:00'))->toBe(1.0)
        ->and($feature->cast(86400 * 3))->toBe(3.0);
});

it('casts categories, enums and missing values', function (): void {
    expect(Feature::castCategorical(null))->toBe('?')
        ->and(Feature::castCategorical(false))->toBe('false')
        ->and(Feature::castCategorical(Task::Regressor))->toBe('regressor')
        ->and(Feature::castCategorical(3))->toBe('3');
});

it('joins text arrays and reads values with resolvers', function (): void {
    $text = Feature::text('tags');
    $resolved = Feature::numeric('orders', fn(array $user): int => count($user['orders']));

    expect($text->cast(['red', 'shoes']))->toBe('red shoes')
        ->and($text->cast(null))->toBe('')
        ->and($resolved->read(['orders' => [1, 2, 3]]))->toBe(3);
});

it('rejects values of the wrong kind', function (): void {
    Feature::numeric('age')->cast('forty');
})->throws(InvalidDataset::class, 'feature "age" expects a number, got "forty"');

it('infers types from sample values', function (array $values, FeatureType $type): void {
    expect(Feature::inferType($values))->toBe($type);
})->with([
    'numbers' => [[null, 3, 4], FeatureType::Numeric],
    'numeric strings' => [['12.5'], FeatureType::Numeric],
    'booleans' => [[true, false], FeatureType::Boolean],
    'dates' => [[new DateTimeImmutable()], FeatureType::Date],
    'categories' => [['pro', 'basic'], FeatureType::Categorical],
    'text' => [['this is a rather long product review with many words'], FeatureType::Text],
]);

it('builds schemas from every supported definition format', function (): void {
    $schema = FeatureSchema::from([
        'age',
        'plan' => 'categorical',
        'bio' => FeatureType::Text,
        'orders' => fn(array $row): int => 1,
        Feature::date('signed_up_at'),
    ]);

    expect($schema->names())->toBe(['age', 'plan', 'bio', 'orders', 'signed_up_at'])
        ->and($schema->isResolved())->toBeFalse()
        ->and($schema->offsetsOf(FeatureType::Text))->toBe([2]);

    $resolved = $schema->resolve([[30, 'pro', 'text', 1, null]]);

    expect($resolved->isResolved())->toBeTrue()
        ->and($resolved->features[0]->type)->toBe(FeatureType::Numeric)
        ->and($resolved->features[3]->resolver)->not->toBeNull();
});

it('rejects duplicate names and unknown types', function (array $definition, string $message): void {
    expect(fn() => FeatureSchema::from($definition))->toThrow(InvalidDataset::class, $message);
})->with([
    'duplicate' => [['age', Feature::numeric('age')], 'unique'],
    'unknown type' => [['age' => 'integer'], 'unknown type "integer"'],
    'empty' => [[], 'at least one feature'],
]);

it('keeps local resolvers when taking types from stored metadata', function (): void {
    $local = FeatureSchema::from(['orders' => fn(array $row): int => 5]);
    $stored = FeatureSchema::fromArray([['name' => 'orders', 'type' => 'numeric']]);

    $merged = $local->withTypesFrom($stored);

    expect($merged->features[0]->type)->toBe(FeatureType::Numeric)
        ->and($merged->extract([]))->toBe([5.0]);
});
