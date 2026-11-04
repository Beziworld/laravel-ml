<?php

declare(strict_types=1);

namespace Beziworld\Ml\Concerns;

use Beziworld\Ml\Data\Prediction;
use Beziworld\Ml\Data\Recommendation;
use Beziworld\Ml\MlManager;
use Beziworld\Ml\Models\MachineLearningModel;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;

/**
 * Lets an Eloquent model run machine learning models on itself.
 *
 *     $user->predict(ChurnModel::class)->is(true);
 *     $user->probabilities(ChurnModel::class);    // ['true' => 0.82, 'false' => 0.18]
 *     $order->isAnomalous(OrderAnomalies::class);
 *     $product->recommendations(SimilarProducts::class, 4);
 */
trait HasPredictions
{
    /**
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     */
    public function predict(MachineLearningModel|string $model, ?string $version = null): Prediction
    {
        return Container::getInstance()->make(MlManager::class)->predict($model, $this, $version);
    }

    /**
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     * @return array<string, float>
     */
    public function probabilities(MachineLearningModel|string $model): array
    {
        return $this->predict($model)->probabilities;
    }

    /**
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     */
    public function isAnomalous(MachineLearningModel|string $model): bool
    {
        return $this->predict($model)->anomaly === true;
    }

    /**
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     * @return Collection<int, Recommendation>
     */
    public function recommendations(MachineLearningModel|string $model, ?int $limit = null): Collection
    {
        return Container::getInstance()->make(MlManager::class)->recommend($model, $this, $limit);
    }
}
