<?php

declare(strict_types=1);

namespace Beziworld\Ml\Models;

use Beziworld\Ml\Enums\Task;
use Rubix\ML\Estimator;
use Rubix\ML\Learner;

/**
 * Flags unusual records (suspicious orders, odd sign-ups). Default estimator: isolation forest
 * with the expected share of anomalies given by contamination().
 */
abstract class AnomalyDetectionModel extends MachineLearningModel
{
    public function task(): Task
    {
        return Task::AnomalyDetector;
    }

    /**
     * Expected share of anomalies in the training data (0 to 0.5); null lets the estimator decide.
     */
    public function contamination(): ?float
    {
        return 0.05;
    }

    public function estimator(): Learner&Estimator
    {
        return Estimators::anomalyDetector('isolation_forest', $this->contamination());
    }
}
