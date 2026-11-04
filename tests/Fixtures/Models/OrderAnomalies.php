<?php

declare(strict_types=1);

namespace Beziworld\Ml\Tests\Fixtures\Models;

use Beziworld\Ml\Dataset\Dataset;
use Beziworld\Ml\Dataset\DatasetBuilder;
use Beziworld\Ml\Models\AnomalyDetectionModel;
use Beziworld\Ml\Tests\Fixtures\Synthetic;
use Illuminate\Container\Container;

final class OrderAnomalies extends AnomalyDetectionModel
{
    /** @var list<array<string, mixed>>|null */
    public static ?array $records = null;

    /**
     * @return array<array-key, mixed>
     */
    public function features(): array
    {
        return ['amount' => 'numeric', 'items' => 'numeric'];
    }

    public function dataset(): Dataset
    {
        return Container::getInstance()->make(DatasetBuilder::class)
            ->fromCollection(self::$records ?? Synthetic::orders(), $this->features(), 'fraud')
            ->get();
    }
}
