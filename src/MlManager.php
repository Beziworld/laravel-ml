<?php

declare(strict_types=1);

namespace Beziworld\Ml;

use Beziworld\Ml\Association\Apriori;
use Beziworld\Ml\Data\DriftReport;
use Beziworld\Ml\Data\EvaluationReport;
use Beziworld\Ml\Data\ModelMetadata;
use Beziworld\Ml\Data\Prediction;
use Beziworld\Ml\Data\Recommendation;
use Beziworld\Ml\Data\TrainingReport;
use Beziworld\Ml\Dataset\Dataset;
use Beziworld\Ml\Dataset\DatasetBuilder;
use Beziworld\Ml\Dataset\FeatureSchema;
use Beziworld\Ml\Drift\DriftDetector;
use Beziworld\Ml\Events\ModelPromoted;
use Beziworld\Ml\Exceptions\InvalidModelDefinition;
use Beziworld\Ml\Jobs\TrainModel;
use Beziworld\Ml\Models\AdHocModel;
use Beziworld\Ml\Models\MachineLearningModel;
use Beziworld\Ml\Models\ModelBuilder;
use Beziworld\Ml\Models\ModelResolver;
use Beziworld\Ml\Models\StoredModel;
use Beziworld\Ml\Persistence\ModelRepository;
use Beziworld\Ml\Prediction\Predictor;
use Beziworld\Ml\Preprocessing\TransformerFactory;
use Beziworld\Ml\Training\Trainer;
use Beziworld\Ml\Training\Validation;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;

/**
 * Entry point behind the Ml facade.
 */
final readonly class MlManager
{
    public function __construct(
        private MlConfig $config,
        private ModelResolver $resolver,
        private ModelRepository $repository,
        private Trainer $trainer,
        private Predictor $predictor,
        private DriftDetector $drift,
        private TransformerFactory $transformers,
        private Dispatcher $events,
        private BusDispatcher $bus,
    ) {}

    /**
     * Start a fluent dataset: fromQuery(), fromCollection(), fromCsv() or fromArray().
     */
    public function dataset(): DatasetBuilder
    {
        return new DatasetBuilder($this->transformers);
    }

    /**
     * Start an ad-hoc model definition.
     */
    public function model(string $name): ModelBuilder
    {
        return new ModelBuilder($name, $this);
    }

    /**
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     */
    public function definition(MachineLearningModel|string $model): MachineLearningModel
    {
        return $this->resolver->resolve($model);
    }

    /**
     * Train synchronously and store a new version.
     *
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     */
    public function train(MachineLearningModel|string $model, ?Dataset $dataset = null, ?Validation $validation = null, ?bool $promote = null): TrainingReport
    {
        return $this->trainer->train($this->resolver->resolve($model), $dataset, $validation, $promote);
    }

    /**
     * Queue a TrainModel job for a definition class (ad-hoc models cannot be queued).
     *
     * @param class-string<MachineLearningModel>|string $model
     */
    public function queue(string $model, ?Validation $validation = null, ?bool $promote = null): TrainModel
    {
        if (is_a($model, AdHocModel::class, true) || is_a($model, StoredModel::class, true)) {
            throw InvalidModelDefinition::because($model, 'ad-hoc and restored models cannot be queued; use a MachineLearningModel class.');
        }

        $definition = $this->resolver->resolve($model);

        if ($definition instanceof AdHocModel || $definition instanceof StoredModel) {
            throw InvalidModelDefinition::because($definition->name(), 'ad-hoc models cannot be queued; use a MachineLearningModel class.');
        }

        $job = new TrainModel($definition::class, $validation?->strategy, $validation?->ratio, $validation?->folds, $promote);

        $this->bus->dispatch($job);

        return $job;
    }

    /**
     * Score a stored version (active by default) on a dataset (the definition's data by default).
     *
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     */
    public function evaluate(MachineLearningModel|string $model, ?string $version = null, ?Dataset $dataset = null): EvaluationReport
    {
        $definition = $this->resolver->resolve($model);
        [$trained, $metadata] = $this->predictor->prepare($definition, $version);

        return $this->trainer->evaluate($definition, $trained, $metadata, $dataset ?? $definition->dataset());
    }

    /**
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     */
    public function predict(MachineLearningModel|string $model, mixed $subject, ?string $version = null): Prediction
    {
        return $this->predictor->predict($this->resolver->resolve($model), $subject, $version);
    }

    /**
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     * @param iterable<mixed> $subjects
     * @return Collection<int, Prediction>
     */
    public function predictMany(MachineLearningModel|string $model, iterable $subjects, ?string $version = null): Collection
    {
        return collect($this->predictor->predictMany($this->resolver->resolve($model), $subjects, $version));
    }

    /**
     * Class probabilities of a classifier (empty when the estimator is not probabilistic).
     *
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     * @return array<string, float>
     */
    public function probabilities(MachineLearningModel|string $model, mixed $subject, ?string $version = null): array
    {
        return $this->predict($model, $subject, $version)->probabilities;
    }

    /**
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     * @return Collection<int, Recommendation>
     */
    public function recommend(MachineLearningModel|string $model, mixed $subject, ?int $limit = null, ?string $version = null): Collection
    {
        return collect($this->predictor->recommend($this->resolver->resolve($model), $subject, $limit, $version));
    }

    /**
     * Compare new records (or a dataset) with the training statistics of a version.
     *
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     * @param iterable<mixed>|Dataset $records
     */
    public function drift(MachineLearningModel|string $model, iterable|Dataset $records, ?string $version = null): DriftReport
    {
        $definition = $this->resolver->resolve($model);
        $metadata = $this->repository->metadata($definition->name(), $version);

        if (! $records instanceof Dataset) {
            $schema = $definition->schema()->withTypesFrom(FeatureSchema::fromArray($metadata->features));
            $records = $this->dataset()->fromCollection($records, $schema->features)->get();
        }

        return $this->drift->compare($metadata, $records);
    }

    /**
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     * @return list<ModelMetadata>
     */
    public function versions(MachineLearningModel|string $model): array
    {
        return $this->repository->versions($this->name($model));
    }

    /**
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     */
    public function promote(MachineLearningModel|string $model, string $version): ModelMetadata
    {
        $name = $this->name($model);
        $previous = $this->repository->promote($name, $version);

        $this->events->dispatch(new ModelPromoted($name, $version, $previous));

        return $this->repository->metadata($name, $version);
    }

    /**
     * Re-activate the version before the active one.
     *
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     */
    public function rollback(MachineLearningModel|string $model): ModelMetadata
    {
        $name = $this->name($model);
        $previous = $this->repository->activeVersion($name);
        $version = $this->repository->rollback($name);

        $this->events->dispatch(new ModelPromoted($name, $version, $previous));

        return $this->repository->metadata($name, $version);
    }

    /**
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     * @return list<string> deleted versions
     */
    public function prune(MachineLearningModel|string $model, ?int $keep = null): array
    {
        return $this->repository->prune($this->name($model), $keep);
    }

    /**
     * Apriori association rule learner (market basket analysis).
     */
    public function apriori(float $support = 0.1, float $confidence = 0.5, int $maxLength = 0): Apriori
    {
        return new Apriori($support, $confidence, $maxLength);
    }

    public function repository(): ModelRepository
    {
        return $this->repository;
    }

    public function config(): MlConfig
    {
        return $this->config;
    }

    /**
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     */
    private function name(MachineLearningModel|string $model): string
    {
        if ($model instanceof MachineLearningModel) {
            return $model->name();
        }

        if (! isset($this->config->models[$model]) && ! class_exists($model)) {
            return $model;
        }

        return $this->resolver->resolve($model)->name();
    }
}
