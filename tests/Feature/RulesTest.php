<?php

declare(strict_types=1);

use Beziworld\Ml\Facades\Ml;
use Beziworld\Ml\Rules\ClassifiedAs;
use Beziworld\Ml\Rules\NotAnomalous;
use Beziworld\Ml\Tests\Fixtures\Models\OrderAnomalies;
use Beziworld\Ml\Tests\Fixtures\Models\SpamFilter;
use Beziworld\Ml\Training\Validation;
use Illuminate\Support\Facades\Validator;

it('accepts messages classified as ham and rejects spam', function (): void {
    Ml::train(SpamFilter::class, validation: Validation::none());

    $rules = ['message' => ['required', new ClassifiedAs(SpamFilter::class, 'ham')]];

    expect(Validator::make(['message' => 'see you at the weekly meeting'], $rules)->passes())->toBeTrue();

    $validator = Validator::make(['message' => 'win free cash prize now'], $rules);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('message'))->toBe('The message was not recognised as valid.');
});

it('requires a minimum confidence when asked', function (): void {
    Ml::train(SpamFilter::class, validation: Validation::none());

    $rules = ['message' => [new ClassifiedAs(SpamFilter::class, ['ham'], minConfidence: 1.01)]];

    expect(Validator::make(['message' => 'see you at the weekly meeting'], $rules)->fails())->toBeTrue();
});

it('rejects anomalous orders from the whole payload', function (): void {
    Ml::train(OrderAnomalies::class);

    $rules = ['amount' => [new NotAnomalous(OrderAnomalies::class)]];

    expect(Validator::make(['amount' => 99.0, 'items' => 2], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['amount' => 8000.0, 'items' => 55], $rules)->errors()->first('amount'))->toBe('The amount looks unusual and was rejected.');
});
