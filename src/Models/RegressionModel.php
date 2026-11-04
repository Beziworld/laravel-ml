<?php

declare(strict_types=1);

namespace Beziworld\Ml\Models;

use Beziworld\Ml\Enums\Task;

/**
 * Predicts a continuous value (price, lifetime value, delivery time). Default estimator: gradient boosted regression trees.
 */
abstract class RegressionModel extends MachineLearningModel
{
    public function task(): Task
    {
        return Task::Regressor;
    }
}
