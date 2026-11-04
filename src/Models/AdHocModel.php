<?php

declare(strict_types=1);

namespace Beziworld\Ml\Models;

use Beziworld\Ml\Dataset\Dataset;
use Beziworld\Ml\Enums\Task;
use Beziworld\Ml\Exceptions\InvalidModelDefinition;
use Beziworld\Ml\Preprocessing\Preprocessing;
use Beziworld\Ml\Training\Validation;
use Closure;
use Rubix\ML\Estimator;
use Rubix\ML\Learner;
use Rubix\ML\Transformers\Transformer;

/**
 * Model definition assembled at runtime by the fluent ModelBuilder.
 */
final class AdHocModel extends MachineLearningModel
{
    /**
     * @param array<array-key, mixed> $modelFeatures
     * @param (Closure(): (Learner&Estimator))|null $estimator
     * @param string|(Closure(mixed): mixed)|null $modelLabel
     * @param (Closure(): list<Transformer>)|null $transformers
     * @param string|(Closure(mixed): mixed)|null $modelKey
     */
    public function __construct(
        private readonly string $modelName,
        private readonly Task $modelTask,
        private readonly array $modelFeatures,
        private readonly ?Closure $estimator = null,
        private readonly string|Closure|null $modelLabel = null,
        private readonly ?Preprocessing $modelPreprocessing = null,
        private readonly ?Closure $transformers = null,
        private readonly ?Dataset $modelDataset = null,
        private readonly string|Closure|null $modelKey = null,
        private readonly ?Validation $modelValidation = null,
        private readonly ?int $modelSeed = null,
    ) {}

    public function name(): string
    {
        return $this->modelName;
    }

    public function task(): Task
    {
        return $this->modelTask;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function features(): array
    {
        return $this->modelFeatures;
    }

    public function estimator(): Learner&Estimator
    {
        return $this->estimator !== null ? ($this->estimator)() : parent::estimator();
    }

    /**
     * @return string|(Closure(mixed): mixed)|null
     */
    public function label(): string|Closure|null
    {
        return $this->modelLabel;
    }

    public function preprocessing(): ?Preprocessing
    {
        return $this->modelPreprocessing;
    }

    /**
     * @return list<Transformer>|null
     */
    public function transformers(): ?array
    {
        return $this->transformers !== null ? ($this->transformers)() : null;
    }

    public function dataset(): Dataset
    {
        return $this->modelDataset ?? throw InvalidModelDefinition::because($this->modelName, 'no training data; call using() on the builder or pass a dataset to Ml::train().');
    }

    /**
     * @return string|(Closure(mixed): mixed)|null
     */
    public function key(): string|Closure|null
    {
        return $this->modelKey ?? ($this->modelTask === Task::Recommender ? 'id' : null);
    }

    public function validation(): ?Validation
    {
        return $this->modelValidation;
    }

    public function seed(): ?int
    {
        return $this->modelSeed;
    }
}
