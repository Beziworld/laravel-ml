<?php

declare(strict_types=1);

namespace Beziworld\Ml\Tests\Fixtures\Models;

use Beziworld\Ml\Dataset\Dataset;
use Beziworld\Ml\Dataset\DatasetBuilder;
use Beziworld\Ml\Models\TextClassificationModel;
use Beziworld\Ml\Tests\Fixtures\Synthetic;
use Illuminate\Container\Container;

final class SpamFilter extends TextClassificationModel
{
    public function text(): string
    {
        return 'message';
    }

    public function label(): string
    {
        return 'spam';
    }

    public function stopWords(): string
    {
        return 'english';
    }

    public function dataset(): Dataset
    {
        return Container::getInstance()->make(DatasetBuilder::class)
            ->fromCollection(Synthetic::messages(), $this->features(), $this->label())
            ->get();
    }
}
