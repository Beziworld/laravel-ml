<?php

declare(strict_types=1);

namespace Beziworld\Ml\Tests\Fixtures\Models;

use Beziworld\Ml\Dataset\Dataset;
use Beziworld\Ml\Dataset\DatasetBuilder;
use Beziworld\Ml\Models\ClusteringModel;
use Beziworld\Ml\Tests\Fixtures\Synthetic;
use Illuminate\Container\Container;

final class CustomerSegments extends ClusteringModel
{
    /**
     * @return array<array-key, mixed>
     */
    public function features(): array
    {
        return ['recency' => 'numeric', 'frequency' => 'numeric'];
    }

    public function dataset(): Dataset
    {
        return Container::getInstance()->make(DatasetBuilder::class)
            ->fromCollection(Synthetic::segments(), $this->features(), 'segment')
            ->get();
    }
}
