<?php

declare(strict_types=1);

namespace Beziworld\Ml\Models;

use Beziworld\Ml\Enums\Task;
use Closure;

/**
 * Content-based recommender: items are indexed by their transformed feature vectors in a
 * ball tree and the k nearest items are returned as recommendations ("similar products").
 */
abstract class RecommendationModel extends MachineLearningModel
{
    public function task(): Task
    {
        return Task::Recommender;
    }

    /**
     * @return string|(Closure(mixed): mixed)|null
     */
    public function key(): string|Closure|null
    {
        return 'id';
    }

    /**
     * Number of recommendations returned when none is requested explicitly.
     */
    public function neighbours(): int
    {
        return 5;
    }
}
