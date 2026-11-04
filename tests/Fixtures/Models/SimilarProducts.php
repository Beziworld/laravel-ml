<?php

declare(strict_types=1);

namespace Beziworld\Ml\Tests\Fixtures\Models;

use Beziworld\Ml\Dataset\Dataset;
use Beziworld\Ml\Dataset\DatasetBuilder;
use Beziworld\Ml\Models\RecommendationModel;
use Beziworld\Ml\Tests\Fixtures\Synthetic;
use Illuminate\Container\Container;

final class SimilarProducts extends RecommendationModel
{
    /**
     * @return array<array-key, mixed>
     */
    public function features(): array
    {
        return ['category' => 'categorical', 'price' => 'numeric', 'rating' => 'numeric'];
    }

    public function neighbours(): int
    {
        return 2;
    }

    public function dataset(): Dataset
    {
        return Container::getInstance()->make(DatasetBuilder::class)
            ->fromCollection(Synthetic::products(), $this->features(), key: 'id')
            ->get();
    }
}
