<?php

declare(strict_types=1);

namespace Beziworld\Ml\Training;

use Beziworld\Ml\Data\EvaluationReport;
use Beziworld\Ml\Data\ModelMetadata;
use Beziworld\Ml\Data\TrainingReport;
use Beziworld\Ml\Dataset\Dataset;
use Beziworld\Ml\Enums\Task;
use Beziworld\Ml\Events\ModelPromoted;
use Beziworld\Ml\Events\ModelTrained;
use Beziworld\Ml\Exceptions\InvalidDataset;
use Beziworld\Ml\Exceptions\InvalidModelDefinition;
use Beziworld\Ml\MlConfig;
use Beziworld\Ml\Models\MachineLearningModel;
use Beziworld\Ml\Models\TrainedModel;
use Beziworld\Ml\Persistence\ModelRepository;
use Beziworld\Ml\Preprocessing\Preprocessing;
use Beziworld\Ml\Preprocessing\TransformerFactory;
use Beziworld\Ml\Support\Rubix;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Estimator;
use Rubix\ML\Graph\Trees\BallTree;
use Rubix\ML\Learner;
use Rubix\ML\Transformers\Pipeline;

/**
 * Validates, fits, stores and (optionally) promotes model versions.
 */
final readonly class Trainer
{
    public function __construct(
        private MlConfig $config,
        private TransformerFactory $transformers,
        private ModelRepository $repository,
        private Dispatcher $events,
    ) {}

    /**
     * Train a model definition and store the result as a new version.
     *
     * @param bool|null $promote null follows ml.auto_promote (the first version is always promoted)
     */
    public function train(MachineLearningModel $definition, ?Dataset $dataset = null, ?Validation $validation = null, ?bool $promote = null): TrainingReport
    {
        $this->applyLimits();
        $this->seed($definition);

        $started = hrtime(true);
        $dataset ??= $definition->dataset();
        $task = $definition->task();

        $this->guard($definition, $dataset);

        $validation = $task->supportsCrossValidation()
            ? ($validation ?? $definition->validation() ?? $this->config->validation)
            : Validation::none();

        [$metrics, $matrix, $testSamples] = $this->validate($definition, $dataset, $validation);

        $model = $this->fit($definition, $dataset);

        if (! $task->supportsCrossValidation()) {
            [$metrics, $matrix] = [$this->inSampleMetrics($model, $dataset, $task), null];
        }

        $durationMs = round((hrtime(true) - $started) / 1e6, 2);
        $trainedAt = CarbonImmutable::now();
        $hash = $dataset->hash();

        $metadata = $this->repository->store($model, new ModelMetadata(
            name: $definition->name(),
            version: '',
            task: $task->value,
            estimator: $model->estimator !== null ? $model->estimator::class : BallTree::class,
            features: $dataset->schema()->toArray(),
            label: $dataset->labelName(),
            metrics: $metrics,
            validation: $validation->describe(),
            samples: $dataset->count(),
            trainedAt: $trainedAt,
            datasetHash: $hash,
            checksum: '',
            signature: null,
            classes: [],
            statistics: $dataset->statistics(),
            preprocessing: $definition->transformers() !== null ? ['custom' => true] : $this->preprocessing($definition, $dataset)->toArray(),
            durationMs: $durationMs,
            packageVersion: Rubix::packageVersion(),
            rubixVersion: Rubix::version(),
        ));

        $promoted = $promote ?? ($this->config->autoPromote || $this->repository->activeVersion($definition->name()) === null);

        if ($promoted) {
            $previous = $this->repository->promote($definition->name(), $metadata->version);
            $this->events->dispatch(new ModelPromoted($definition->name(), $metadata->version, $previous));
        }

        $report = new TrainingReport(
            model: $definition->name(),
            version: $metadata->version,
            task: $task->value,
            validation: $validation->describe(),
            samples: $dataset->count(),
            testSamples: $testSamples,
            metrics: $metrics,
            confusionMatrix: $matrix,
            durationMs: $durationMs,
            trainedAt: $trainedAt,
            datasetHash: $hash,
            promoted: $promoted,
        );

        $this->events->dispatch(new ModelTrained($report, $metadata->withActive($promoted)));

        return $report;
    }

    /**
     * Fit the transformers and the estimator on a dataset without storing anything.
     */
    public function fit(MachineLearningModel $definition, Dataset $dataset): TrainedModel
    {
        $task = $definition->task();
        $pipeline = new Pipeline($definition->transformers() ?? $this->transformers->make($dataset->schema(), $this->preprocessing($definition, $dataset)));
        $rubix = $dataset->toRubix($task);

        $pipeline->fit($rubix);
        $rubix->apply($pipeline);

        if ($task === Task::Recommender) {
            $keys = $dataset->keys();

            if ($keys === []) {
                throw InvalidModelDefinition::because($definition->name(), 'a recommender needs record keys; return the key column from key().');
            }

            $index = new BallTree();
            $index->grow(Labeled::quick($rubix->samples(), $keys));

            return new TrainedModel($task, $pipeline, null, $index);
        }

        $estimator = Rubix::quietly(static fn(): Learner&Estimator => $definition->estimator());

        $this->guardEstimator($definition, $estimator, $task);

        Rubix::quietly(static fn() => $estimator->train($rubix));

        return new TrainedModel($task, $pipeline, $estimator);
    }

    /**
     * Score a stored version on a dataset (by default the definition's current training data).
     */
    public function evaluate(MachineLearningModel $definition, TrainedModel $model, ModelMetadata $metadata, Dataset $dataset): EvaluationReport
    {
        $task = $definition->task();

        $this->guard($definition, $dataset);

        $matrix = null;
        $samples = $dataset->samples();

        if ($task === Task::Classifier) {
            ['metrics' => $metrics, 'confusion_matrix' => $matrix] = Evaluator::classification($model->predict($samples), $dataset->labelsFor($task));
        } elseif ($task === Task::Regressor) {
            $metrics = Evaluator::regression($model->predict($samples), $dataset->labelsFor($task));
        } else {
            $metrics = $this->inSampleMetrics($model, $dataset, $task);
        }

        return new EvaluationReport($metadata->name, $metadata->version, $task->value, $dataset->count(), $metrics, $matrix, CarbonImmutable::now());
    }

    /**
     * @return array{0: array<string, float>, 1: array<string, array<string, int>>|null, 2: int}
     */
    private function validate(MachineLearningModel $definition, Dataset $dataset, Validation $validation): array
    {
        if ($validation->strategy === Validation::NONE) {
            return [[], null, 0];
        }

        $task = $definition->task();
        $labels = $dataset->labelsFor($task);
        $stratify = $task === Task::Classifier ? $labels : null;

        $splits = $validation->strategy === Validation::KFOLD
            ? Splitter::kFold($dataset->count(), $validation->folds, $stratify)
            : [Splitter::holdout($dataset->count(), $validation->ratio, $stratify)];

        $predictions = [];
        $truth = [];

        foreach ($splits as [$train, $test]) {
            $model = $this->fit($definition, $dataset->subset($train));
            $testSet = $dataset->subset($test);

            array_push($predictions, ...$model->predict($testSet->samples()));
            array_push($truth, ...$testSet->labelsFor($task));
        }

        $testSamples = count($truth);

        if ($task === Task::Classifier) {
            ['metrics' => $metrics, 'confusion_matrix' => $matrix] = Evaluator::classification($predictions, $truth);

            return [$metrics, $matrix, $testSamples];
        }

        return [Evaluator::regression($predictions, $truth), null, $testSamples];
    }

    /**
     * @return array<string, float>
     */
    private function inSampleMetrics(TrainedModel $model, Dataset $dataset, Task $task): array
    {
        if ($task === Task::Recommender) {
            return ['items' => (float) $dataset->count()];
        }

        $samples = $dataset->samples();
        $predictions = $model->predict($samples);

        if ($task === Task::Clusterer) {
            return Evaluator::clustering($predictions, $model->transform($samples), $dataset->hasLabels() ? $dataset->labelsFor(Task::Classifier) : null);
        }

        return Evaluator::anomaly($predictions, $dataset->hasLabels() ? $dataset->labelsFor(Task::AnomalyDetector) : null);
    }

    private function preprocessing(MachineLearningModel $definition, Dataset $dataset): Preprocessing
    {
        return $definition->preprocessing() ?? $dataset->preprocessing() ?? Preprocessing::defaults();
    }

    private function guard(MachineLearningModel $definition, Dataset $dataset): void
    {
        if ($dataset->isEmpty()) {
            throw InvalidDataset::because(sprintf('model "%s" received an empty dataset.', $definition->name()));
        }

        if ($definition->task()->isSupervised() && ! $dataset->hasLabels()) {
            throw InvalidModelDefinition::because($definition->name(), 'a supervised model needs a label; return it from label().');
        }
    }

    private function guardEstimator(MachineLearningModel $definition, Learner&Estimator $estimator, Task $task): void
    {
        $type = $estimator->type();

        $matches = match ($task) {
            Task::Classifier => $type->isClassifier(),
            Task::Regressor => $type->isRegressor(),
            Task::Clusterer => $type->isClusterer(),
            Task::AnomalyDetector => $type->isAnomalyDetector(),
            Task::Recommender => true,
        };

        if (! $matches) {
            throw InvalidModelDefinition::because($definition->name(), sprintf('the estimator is a %s but the task is %s.', $type, $task->value));
        }
    }

    private function seed(MachineLearningModel $definition): void
    {
        $seed = $definition->seed() ?? $this->config->seed;

        if ($seed !== null) {
            mt_srand($seed);
        }
    }

    private function applyLimits(): void
    {
        if ($this->config->memoryLimit !== null) {
            ini_set('memory_limit', $this->config->memoryLimit);
        }

        if ($this->config->timeLimit !== null) {
            set_time_limit($this->config->timeLimit);
        }
    }
}
