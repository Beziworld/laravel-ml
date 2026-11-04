<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Model storage
    |--------------------------------------------------------------------------
    |
    | Trained models are written to a Laravel filesystem disk. Every training
    | run creates a new version directory ("v1", "v2", ...) holding the
    | serialized model and a metadata JSON file.
    |
    */

    'disk' => env('ML_DISK', 'local'),

    'path' => env('ML_PATH', 'ml-models'),

    // Promote every freshly trained version to "active" automatically.
    'auto_promote' => env('ML_AUTO_PROMOTE', true),

    // Number of versions kept per model by "ml:prune" (the active one is never removed).
    'keep_versions' => env('ML_KEEP_VERSIONS', 5),

    /*
    |--------------------------------------------------------------------------
    | Model aliases
    |--------------------------------------------------------------------------
    |
    | Short names accepted by the facade and the Artisan commands,
    | e.g. 'churn' => App\Ml\ChurnModel::class.
    |
    */

    'models' => [],

    /*
    |--------------------------------------------------------------------------
    | Cross validation
    |--------------------------------------------------------------------------
    |
    | strategy: "holdout", "kfold" or "none".
    |
    */

    'validation' => [
        'strategy' => env('ML_VALIDATION', 'holdout'),
        'ratio' => env('ML_HOLDOUT_RATIO', 0.2),
        'folds' => env('ML_FOLDS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Training
    |--------------------------------------------------------------------------
    |
    | seed makes training reproducible (mt_srand). memory_limit and time_limit
    | are applied with ini_set() and set_time_limit() while a model is
    | trained; time_limit is also the timeout of the queued job.
    |
    */

    'training' => [
        'seed' => env('ML_SEED'),
        'memory_limit' => env('ML_MEMORY_LIMIT'),
        'time_limit' => env('ML_TIME_LIMIT'),
    ],

    'queue' => [
        'connection' => env('ML_QUEUE_CONNECTION'),
        'name' => env('ML_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Text features
    |--------------------------------------------------------------------------
    */

    'text' => [
        'max_vocabulary' => env('ML_TEXT_MAX_VOCABULARY', 10000),
        'min_document_count' => env('ML_TEXT_MIN_DOCUMENT_COUNT', 1),
        'max_document_ratio' => env('ML_TEXT_MAX_DOCUMENT_RATIO', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model file security
    |--------------------------------------------------------------------------
    |
    | Model files are PHP serialized objects. They are verified with a SHA-256
    | checksum and an HMAC-SHA256 signature before unserialize() runs with a
    | restricted class list. The signing key falls back to APP_KEY.
    | Never load model files that come from an untrusted source.
    |
    */

    'security' => [
        'signing_key' => env('ML_SIGNING_KEY'),
        'require_signature' => env('ML_REQUIRE_SIGNATURE', true),
        'allowed_namespaces' => [
            'Rubix\\ML\\',
            'Tensor\\',
            'OkBloomer\\',
            'Beziworld\\Ml\\',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | PredictionMade is dispatched only when enabled, for the given share of
    | predictions (1.0 = every prediction, 0.01 = one in a hundred).
    |
    */

    'events' => [
        'predictions' => env('ML_PREDICTION_EVENTS', false),
        'prediction_sample_rate' => env('ML_PREDICTION_SAMPLE_RATE', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data drift
    |--------------------------------------------------------------------------
    |
    | mean_shift: allowed difference of a numeric mean, in training standard deviations.
    | distribution_shift: allowed total variation distance of a categorical distribution.
    | missing_shift: allowed change of the missing value rate.
    |
    */

    'drift' => [
        'mean_shift' => env('ML_DRIFT_MEAN_SHIFT', 0.5),
        'distribution_shift' => env('ML_DRIFT_DISTRIBUTION_SHIFT', 0.2),
        'missing_shift' => env('ML_DRIFT_MISSING_SHIFT', 0.1),
    ],

];
