<?php

declare(strict_types=1);

use Beziworld\Ml\Data\ModelMetadata;
use Beziworld\Ml\Enums\Task;
use Beziworld\Ml\Exceptions\IntegrityCheckFailed;
use Beziworld\Ml\MlConfig;
use Beziworld\Ml\Models\TrainedModel;
use Beziworld\Ml\Persistence\ModelSerializer;
use Carbon\CarbonImmutable;
use Rubix\ML\Classifiers\KNearestNeighbors;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Transformers\Pipeline;

function trainedModel(): TrainedModel
{
    $estimator = new KNearestNeighbors(1);
    $estimator->train(Labeled::quick([[0.0], [1.0]], ['a', 'b']));

    return new TrainedModel(Task::Classifier, new Pipeline([]), $estimator);
}

/**
 * @param array{payload: string, checksum: string, signature: string|null, classes: list<string>} $encoded
 */
function metadataFor(array $encoded, ?string $checksum = null, ?string $signature = null, ?array $classes = null): ModelMetadata
{
    return new ModelMetadata(
        'test',
        'v1',
        'classifier',
        KNearestNeighbors::class,
        [],
        'y',
        [],
        'none',
        2,
        CarbonImmutable::now(),
        'hash',
        $checksum ?? $encoded['checksum'],
        $signature ?? $encoded['signature'],
        $classes ?? $encoded['classes'],
    );
}

it('records the classes contained in a serialized payload', function (): void {
    $classes = ModelSerializer::classesIn(serialize(trainedModel()));

    expect($classes)->toContain(TrainedModel::class, Task::class, KNearestNeighbors::class, Pipeline::class);
});

it('round trips a signed model', function (): void {
    $serializer = new ModelSerializer(new MlConfig(signingKey: 'secret'));
    $encoded = $serializer->encode(trainedModel());

    $model = $serializer->decode($encoded['payload'], metadataFor($encoded));

    expect($encoded['signature'])->toHaveLength(64)
        ->and($model->predict([[0.9]]))->toBe(['b']);
});

it('refuses modified payloads', function (): void {
    $serializer = new ModelSerializer(new MlConfig(signingKey: 'secret'));
    $encoded = $serializer->encode(trainedModel());

    $serializer->decode($encoded['payload'] . 'x', metadataFor($encoded));
})->throws(IntegrityCheckFailed::class, 'checksum');

it('refuses a payload signed with another key', function (): void {
    $encoded = (new ModelSerializer(new MlConfig(signingKey: 'attacker')))->encode(trainedModel());

    (new ModelSerializer(new MlConfig(signingKey: 'secret')))->decode($encoded['payload'], metadataFor($encoded));
})->throws(IntegrityCheckFailed::class, 'HMAC signature is invalid');

it('refuses classes outside the allowed namespaces before unserialising', function (): void {
    $config = new MlConfig(signingKey: null, requireSignature: false);
    $serializer = new ModelSerializer($config);
    $encoded = $serializer->encode(trainedModel());

    $serializer->decode($encoded['payload'], metadataFor($encoded, classes: [...$encoded['classes'], 'GuzzleHttp\\Psr7\\FnStream']));
})->throws(IntegrityCheckFailed::class, 'outside the allowed namespaces');

it('refuses unsigned models when signatures are required', function (): void {
    $encoded = (new ModelSerializer(new MlConfig(signingKey: null, requireSignature: false)))->encode(trainedModel());

    (new ModelSerializer(new MlConfig(signingKey: 'secret')))->decode($encoded['payload'], metadataFor($encoded));
})->throws(IntegrityCheckFailed::class, 'not signed');
