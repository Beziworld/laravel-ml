<?php

declare(strict_types=1);

namespace Beziworld\Ml\Models;

use Beziworld\Ml\Data\Prediction;
use Beziworld\Ml\Data\TrainingReport;
use Beziworld\Ml\Dataset\Dataset;
use Beziworld\Ml\Dataset\DatasetBuilder;
use Beziworld\Ml\Enums\Task;
use Beziworld\Ml\MlManager;
use Beziworld\Ml\Preprocessing\Preprocessing;
use Beziworld\Ml\Training\Validation;
use Closure;
use Rubix\ML\Estimator;
use Rubix\ML\Learner;
use Rubix\ML\Transformers\Transformer;

/**
 * Fluent builder for ad-hoc models that do not need a definition class.
 *
 *     $report = Ml::model('spam-filter')
 *         ->textClassifier()
 *         ->features(['message' => 'text'])
 *         ->label('is_spam')
 *         ->using(Ml::dataset()->fromCollection($messages))
 *         ->train();
 *
 *     Ml::predict('spam-filter', ['message' => 'Win a free prize']);
 */
final class ModelBuilder
{
    private Task $task = Task::Classifier;

    /** @var (Closure(): (Learner&Estimator))|null */
    private ?Closure $estimator = null;

    /** @var array<array-key, mixed> */
    private array $features = [];

    /** @var string|(Closure(mixed): mixed)|null */
    private string|Closure|null $label = null;

    /** @var string|(Closure(mixed): mixed)|null */
    private string|Closure|null $key = null;

    private ?Preprocessing $preprocessing = null;

    /** @var (Closure(): list<Transformer>)|null */
    private ?Closure $transformers = null;

    private ?Dataset $dataset = null;

    private ?Validation $validation = null;

    private ?int $seed = null;

    public function __construct(
        private readonly string $name,
        private readonly MlManager $manager,
    ) {}

    /**
     * @param (Learner&Estimator)|string|(Closure(): (Learner&Estimator))|null $estimator instance, preset name or factory
     */
    public function classifier(Learner|string|Closure|null $estimator = null): self
    {
        return $this->task(Task::Classifier, $estimator, static fn(string $name): Learner&Estimator => Estimators::classifier($name));
    }

    /**
     * @param (Learner&Estimator)|string|(Closure(): (Learner&Estimator))|null $estimator
     */
    public function regressor(Learner|string|Closure|null $estimator = null): self
    {
        return $this->task(Task::Regressor, $estimator, static fn(string $name): Learner&Estimator => Estimators::regressor($name));
    }

    /**
     * @param (Learner&Estimator)|string|(Closure(): (Learner&Estimator))|null $estimator
     */
    public function clusterer(Learner|string|Closure|null $estimator = null, int $clusters = 3): self
    {
        return $this->task(Task::Clusterer, $estimator ?? 'kmeans', static fn(string $name): Learner&Estimator => Estimators::clusterer($name, $clusters));
    }

    /**
     * @param (Learner&Estimator)|string|(Closure(): (Learner&Estimator))|null $estimator
     */
    public function anomalyDetector(Learner|string|Closure|null $estimator = null, ?float $contamination = 0.05): self
    {
        return $this->task(Task::AnomalyDetector, $estimator ?? 'isolation_forest', static fn(string $name): Learner&Estimator => Estimators::anomalyDetector($name, $contamination));
    }

    public function recommender(string $key = 'id'): self
    {
        $this->task = Task::Recommender;
        $this->key = $key;

        return $this;
    }

    /**
     * Text classifier preset: TF-IDF features and a softmax classifier.
     *
     * @param list<string>|string $stopWords
     */
    public function textClassifier(array|string $stopWords = [], int $ngrams = 1): self
    {
        $this->task(Task::Classifier, 'softmax', static fn(string $name): Learner&Estimator => Estimators::classifier($name));
        $this->preprocessing = Preprocessing::defaults()->withText(true, $stopWords, $ngrams);

        return $this;
    }

    /**
     * @param array<array-key, mixed> $features
     */
    public function features(array $features): self
    {
        $this->features = $features;

        return $this;
    }

    /**
     * @param string|(Closure(mixed): mixed)|null $label
     */
    public function label(string|Closure|null $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @param string|(Closure(mixed): mixed)|null $key
     */
    public function key(string|Closure|null $key): self
    {
        $this->key = $key;

        return $this;
    }

    public function preprocessing(Preprocessing $preprocessing): self
    {
        $this->preprocessing = $preprocessing;

        return $this;
    }

    /**
     * @param Closure(): list<Transformer> $transformers factory returning new transformer instances
     */
    public function transformers(Closure $transformers): self
    {
        $this->transformers = $transformers;

        return $this;
    }

    /**
     * Training data; a builder is completed with the features, label and key given here.
     */
    public function using(Dataset|DatasetBuilder $dataset): self
    {
        if ($dataset instanceof DatasetBuilder) {
            if ($this->features !== []) {
                $dataset->features($this->features);
            }

            if ($this->label !== null) {
                $dataset->label($this->label);
            }

            if ($this->key !== null) {
                $dataset->key($this->key);
            }

            $dataset = $dataset->get();
        }

        $this->dataset = $dataset;

        if ($this->features === []) {
            $this->features = $dataset->schema()->features;
        }

        return $this;
    }

    public function validation(Validation $validation): self
    {
        $this->validation = $validation;

        return $this;
    }

    public function seed(?int $seed): self
    {
        $this->seed = $seed;

        return $this;
    }

    public function make(): AdHocModel
    {
        return new AdHocModel(
            $this->name,
            $this->task,
            $this->features,
            $this->estimator,
            $this->label,
            $this->preprocessing,
            $this->transformers,
            $this->dataset,
            $this->key,
            $this->validation,
            $this->seed,
        );
    }

    public function train(?bool $promote = null): TrainingReport
    {
        return $this->manager->train($this->make(), promote: $promote);
    }

    public function predict(mixed $subject): Prediction
    {
        return $this->manager->predict($this->make(), $subject);
    }

    /**
     * @param (Learner&Estimator)|string|(Closure(): (Learner&Estimator))|null $estimator
     * @param Closure(string): (Learner&Estimator) $preset
     */
    private function task(Task $task, Learner|string|Closure|null $estimator, Closure $preset): self
    {
        $this->task = $task;

        $this->estimator = match (true) {
            $estimator === null => null,
            is_string($estimator) => static fn(): Learner&Estimator => $preset($estimator),
            $estimator instanceof Closure => $estimator,
            default => static fn(): Learner&Estimator => clone $estimator,
        };

        return $this;
    }
}
