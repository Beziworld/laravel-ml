<?php

declare(strict_types=1);

use Beziworld\Ml\Exceptions\InvalidConfiguration;
use Beziworld\Ml\MlConfig;
use Beziworld\Ml\Training\Validation;

it('reads defaults from the published config file', function (): void {
    $config = MlConfig::fromArray(require __DIR__ . '/../../config/ml.php');

    expect($config->disk)->toBe('local')
        ->and($config->path)->toBe('ml-models')
        ->and($config->autoPromote)->toBeTrue()
        ->and($config->validation->strategy)->toBe(Validation::HOLDOUT)
        ->and($config->validation->ratio)->toBe(0.2)
        ->and($config->requireSignature)->toBeTrue()
        ->and($config->allowedNamespaces)->toContain('Rubix\\ML\\');
});

it('requires a disk and a path', function (array $config): void {
    MlConfig::fromArray($config);
})->with([
    'no disk' => [['disk' => '', 'path' => 'models']],
    'no path' => [['disk' => 'local', 'path' => '/']],
])->throws(InvalidConfiguration::class);

it('rejects an unknown validation strategy', function (): void {
    MlConfig::fromArray(['disk' => 'local', 'path' => 'm', 'validation' => ['strategy' => 'bootstrap']]);
})->throws(InvalidConfiguration::class, 'ML_VALIDATION');

it('falls back to the application key for signatures', function (): void {
    expect(MlConfig::fromArray(['disk' => 'local', 'path' => 'm'], 'app-key')->signingKey)->toBe('app-key')
        ->and(MlConfig::fromArray(['disk' => 'local', 'path' => 'm', 'security' => ['signing_key' => 'own']], 'app-key')->signingKey)->toBe('own');
});

it('parses environment strings', function (): void {
    $config = MlConfig::fromArray([
        'disk' => 's3',
        'path' => '/models/',
        'auto_promote' => 'false',
        'validation' => ['strategy' => 'kfold', 'folds' => '10'],
        'training' => ['seed' => '13', 'time_limit' => '600', 'memory_limit' => '2G'],
        'queue' => ['name' => 'ml'],
        'events' => ['predictions' => 'true', 'prediction_sample_rate' => '5'],
    ]);

    expect($config->path)->toBe('models')
        ->and($config->autoPromote)->toBeFalse()
        ->and($config->validation->folds)->toBe(10)
        ->and($config->seed)->toBe(13)
        ->and($config->timeLimit)->toBe(600)
        ->and($config->memoryLimit)->toBe('2G')
        ->and($config->queueName)->toBe('ml')
        ->and($config->predictionEvents)->toBeTrue()
        ->and($config->predictionSampleRate)->toBe(1.0);
});
