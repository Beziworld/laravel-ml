<?php

declare(strict_types=1);

namespace Beziworld\Ml\Models;

use Beziworld\Ml\Data\ModelMetadata;
use Beziworld\Ml\Enums\Task;
use Beziworld\Ml\Exceptions\InvalidModelDefinition;
use Rubix\ML\Estimator;
use Rubix\ML\Learner;

/**
 * Read-only definition rebuilt from stored metadata, so that ad-hoc models can be used for
 * predictions by name. It cannot be retrained because the training source is unknown.
 */
final class StoredModel extends MachineLearningModel
{
    public function __construct(private readonly ModelMetadata $metadata) {}

    public function name(): string
    {
        return $this->metadata->name;
    }

    public function task(): Task
    {
        return Task::from($this->metadata->task);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function features(): array
    {
        $features = [];

        foreach ($this->metadata->features as $feature) {
            $features[$feature['name']] = $feature['type'];
        }

        return $features;
    }

    public function label(): ?string
    {
        return $this->metadata->label;
    }

    public function estimator(): Learner&Estimator
    {
        throw InvalidModelDefinition::because($this->metadata->name, 'a model restored from storage cannot be retrained; train its definition class instead.');
    }
}
