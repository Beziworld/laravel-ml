<?php

declare(strict_types=1);

namespace Beziworld\Ml\Rules;

use Beziworld\Ml\Data\Prediction;

/**
 * Fails when an anomaly detector flags the input as unusual (e.g. a suspicious order).
 *
 *     'order' => ['array', new NotAnomalous(OrderAnomalies::class)],
 */
final class NotAnomalous extends PredictionRule
{
    protected function passes(Prediction $prediction): bool
    {
        return $prediction->anomaly !== true;
    }

    protected function message(): string
    {
        return 'The :attribute looks unusual and was rejected.';
    }
}
