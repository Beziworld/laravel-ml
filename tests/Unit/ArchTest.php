<?php

declare(strict_types=1);

arch('every source file declares strict types')
    ->expect('Beziworld\Ml')
    ->toUseStrictTypes();

arch('no debugging statements are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('data objects are immutable')
    ->expect('Beziworld\Ml\Data')
    ->toBeReadonly();

arch('exceptions share the package base exception')
    ->expect('Beziworld\Ml\Exceptions')
    ->toExtend('Beziworld\Ml\Exceptions\MlException')
    ->ignoring('Beziworld\Ml\Exceptions\MlException');

arch('console commands share the package base command')
    ->expect('Beziworld\Ml\Console')
    ->toExtend('Beziworld\Ml\Console\MlCommand')
    ->ignoring('Beziworld\Ml\Console\MlCommand');

arch('data objects are final and JSON serialisable')
    ->expect('Beziworld\Ml\Data')
    ->toBeFinal()
    ->toImplement('JsonSerializable');

arch('model presets extend the model definition base class')
    ->expect([
        'Beziworld\Ml\Models\ClassificationModel',
        'Beziworld\Ml\Models\RegressionModel',
        'Beziworld\Ml\Models\ClusteringModel',
        'Beziworld\Ml\Models\AnomalyDetectionModel',
        'Beziworld\Ml\Models\RecommendationModel',
        'Beziworld\Ml\Models\TextClassificationModel',
    ])
    ->toExtend('Beziworld\Ml\Models\MachineLearningModel');

arch('the package never calls unserialize outside the model serializer')
    ->expect('unserialize')
    ->toOnlyBeUsedIn('Beziworld\Ml\Persistence\ModelSerializer');
