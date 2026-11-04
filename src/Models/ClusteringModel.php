<?php

declare(strict_types=1);

namespace Beziworld\Ml\Models;

use Beziworld\Ml\Enums\Task;
use Rubix\ML\Estimator;
use Rubix\ML\Learner;

/**
 * Groups records into clusters (customer segmentation). Default estimator: k-means with clusters() groups.
 */
abstract class ClusteringModel extends MachineLearningModel
{
    public function task(): Task
    {
        return Task::Clusterer;
    }

    public function clusters(): int
    {
        return 3;
    }

    public function estimator(): Learner&Estimator
    {
        return Estimators::clusterer('kmeans', $this->clusters());
    }
}
