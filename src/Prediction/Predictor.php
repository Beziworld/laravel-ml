<?php

declare(strict_types=1);

namespace Beziworld\Ml\Prediction;

use Beziworld\Ml\Data\ModelMetadata;
use Beziworld\Ml\Data\Prediction;
use Beziworld\Ml\Data\Recommendation;
use Beziworld\Ml\Dataset\FeatureSchema;
use Beziworld\Ml\Enums\Task;
use Beziworld\Ml\Events\PredictionMade;
use Beziworld\Ml\Exceptions\InvalidModelDefinition;
use Beziworld\Ml\MlConfig;
use Beziworld\Ml\Models\MachineLearningModel;
use Beziworld\Ml\Models\TrainedModel;
use Beziworld\Ml\Persistence\ModelRepository;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Runs stored model versions on arrays, objects and Eloquent models.
 */
final readonly class Predictor
{
    public function __construct(
        private MlConfig $config,
        private ModelRepository $repository,
        private Dispatcher $events,
    ) {}

    public function predict(MachineLearningModel $definition, mixed $subject, ?string $version = null): Prediction
    {
        return $this->predictMany($definition, [$subject], $version)[0];
    }

    /**
     * @param iterable<mixed> $subjects
     * @return list<Prediction>
     */
    public function predictMany(MachineLearningModel $definition, iterable $subjects, ?string $version = null): array
    {
        [$model, $metadata, $schema] = $this->prepare($definition, $version);

        if ($model->task === Task::Recommender) {
            throw InvalidModelDefinition::because($metadata->name, 'recommenders return items; use Ml::recommend() instead of predict().');
        }

        $raw = [];

        foreach ($subjects as $subject) {
            $raw[] = $schema->read($subject);
        }

        if ($raw === []) {
            return [];
        }

        $samples = array_map($schema->cast(...), $raw);
        $values = $model->predict($samples);
        $probabilities = $model->task === Task::Classifier || $model->task === Task::Clusterer ? $model->proba($samples) : null;
        $scores = $model->task === Task::AnomalyDetector ? $model->score($samples) : null;
        $now = CarbonImmutable::now();
        $predictions = [];

        foreach ($values as $offset => $value) {
            $distribution = $probabilities[$offset] ?? [];

            $prediction = new Prediction(
                model: $metadata->name,
                version: $metadata->version,
                task: $model->task->value,
                value: $this->value($value, $model->task),
                probabilities: $distribution,
                confidence: $distribution === [] ? null : round(max($distribution), 6),
                score: isset($scores[$offset]) ? $scores[$offset] : null,
                anomaly: $model->task === Task::AnomalyDetector ? (int) $value === 1 : null,
                predictedAt: $now,
            );

            $predictions[] = $prediction;
            $this->dispatch($prediction, $schema, $raw[$offset]);
        }

        return $predictions;
    }

    /**
     * Nearest items of a recommender for a subject (an item or a profile with the same features).
     * The subject itself is left out when it carries the model key.
     *
     * @return list<Recommendation>
     */
    public function recommend(MachineLearningModel $definition, mixed $subject, ?int $limit = null, ?string $version = null): array
    {
        [$model, $metadata, $schema] = $this->prepare($definition, $version);

        if ($model->task !== Task::Recommender) {
            throw InvalidModelDefinition::because($metadata->name, 'only recommenders can recommend; use predict() for this model.');
        }

        $limit ??= method_exists($definition, 'neighbours') ? (int) $definition->neighbours() : 5;
        $key = $definition->key();
        $own = $key === null ? null : ($key instanceof Closure ? $key($subject) : data_get($subject, $key));
        $own = is_int($own) || is_string($own) ? (string) $own : null;

        $recommendations = [];

        foreach ($model->nearest($schema->extract($subject), $limit + ($own !== null ? 1 : 0)) as $neighbour) {
            if ($own !== null && (string) $neighbour['key'] === $own) {
                continue;
            }

            $recommendations[] = Recommendation::fromDistance($neighbour['key'], $neighbour['distance']);
        }

        return array_slice($recommendations, 0, $limit);
    }

    /**
     * @return array{0: TrainedModel, 1: ModelMetadata, 2: FeatureSchema}
     */
    public function prepare(MachineLearningModel $definition, ?string $version = null): array
    {
        [$model, $metadata] = $this->repository->load($definition->name(), $version);

        $schema = $definition->schema()->withTypesFrom(FeatureSchema::fromArray($metadata->features));

        if ($schema->names() !== $metadata->featureNames()) {
            throw InvalidModelDefinition::because($metadata->name, sprintf(
                'the features changed since %s was trained (stored: %s; defined: %s). Retrain the model.',
                $metadata->version,
                implode(', ', $metadata->featureNames()),
                implode(', ', $schema->names()),
            ));
        }

        return [$model, $metadata, $schema];
    }

    private function value(mixed $value, Task $task): int|float|string
    {
        return match ($task) {
            Task::Regressor => is_numeric($value) ? (float) $value : 0.0,
            Task::Clusterer, Task::AnomalyDetector => is_numeric($value) ? (int) $value : 0,
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    /**
     * @param list<mixed> $raw
     */
    private function dispatch(Prediction $prediction, FeatureSchema $schema, array $raw): void
    {
        if (! $this->config->predictionEvents || $this->config->predictionSampleRate <= 0.0) {
            return;
        }

        if ($this->config->predictionSampleRate < 1.0 && random_int(1, 1_000_000) > (int) round($this->config->predictionSampleRate * 1_000_000)) {
            return;
        }

        $features = [];

        foreach ($schema->names() as $offset => $name) {
            $value = $raw[$offset] ?? null;
            $features[$name] = is_scalar($value) || $value === null ? $value : (is_object($value) && method_exists($value, '__toString') ? (string) $value : get_debug_type($value));
        }

        $this->events->dispatch(new PredictionMade($prediction, $features));
    }
}
