<?php

declare(strict_types=1);

namespace Beziworld\Ml\Tests\Fixtures\Models;

use Beziworld\Ml\Dataset\Dataset;
use Beziworld\Ml\Dataset\DatasetBuilder;
use Beziworld\Ml\Models\Estimators;
use Beziworld\Ml\Models\RegressionModel;
use Beziworld\Ml\Tests\Fixtures\Synthetic;
use Illuminate\Container\Container;
use Rubix\ML\Estimator;
use Rubix\ML\Learner;

final class HousePriceModel extends RegressionModel
{
    /**
     * @return array<array-key, mixed>
     */
    public function features(): array
    {
        return ['area', 'rooms'];
    }

    public function label(): string
    {
        return 'price';
    }

    public function estimator(): Learner&Estimator
    {
        return Estimators::regressor('ridge');
    }

    public function dataset(): Dataset
    {
        return Container::getInstance()->make(DatasetBuilder::class)
            ->fromCollection(Synthetic::houses(), $this->features(), $this->label())
            ->get();
    }
}
