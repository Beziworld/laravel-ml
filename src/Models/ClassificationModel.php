<?php

declare(strict_types=1);

namespace Beziworld\Ml\Models;

use Beziworld\Ml\Enums\Task;

/**
 * Classifies records into discrete classes (churn, fraud, lead quality). Default estimator: random forest.
 */
abstract class ClassificationModel extends MachineLearningModel
{
    public function task(): Task
    {
        return Task::Classifier;
    }
}
