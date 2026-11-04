<?php

declare(strict_types=1);

namespace Beziworld\Ml\Models;

use Beziworld\Ml\Enums\Task;
use Beziworld\Ml\Exceptions\InvalidModelDefinition;
use Rubix\ML\AnomalyDetectors\Scoring;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\Estimator;
use Rubix\ML\Graph\Trees\BallTree;
use Rubix\ML\Probabilistic;
use Rubix\ML\Transformers\Pipeline;

/**
 * The persisted artefact of a training run: the fitted transformer pipeline and the trained
 * estimator (or, for recommenders, a ball tree index over the transformed item vectors).
 */
final class TrainedModel
{
    public function __construct(
        public readonly Task $task,
        public readonly Pipeline $pipeline,
        public readonly ?Estimator $estimator = null,
        public readonly ?BallTree $index = null,
    ) {}

    /**
     * Run cast samples through the fitted transformers.
     *
     * @param list<list<float|string>> $samples
     * @return list<list<mixed>>
     */
    public function transform(array $samples): array
    {
        $this->pipeline->transform($samples);

        return $samples;
    }

    /**
     * @param list<list<float|string>> $samples
     * @return list<mixed>
     */
    public function predict(array $samples): array
    {
        return $this->estimator()->predict(Unlabeled::quick($this->transform($samples)));
    }

    /**
     * Class probabilities, or null when the estimator is not probabilistic.
     *
     * @param list<list<float|string>> $samples
     * @return list<array<string, float>>|null
     */
    public function proba(array $samples): ?array
    {
        $estimator = $this->estimator();

        if (! $estimator instanceof Probabilistic) {
            return null;
        }

        $probabilities = [];

        foreach ($estimator->proba(Unlabeled::quick($this->transform($samples))) as $distribution) {
            $row = [];

            foreach ($distribution as $class => $probability) {
                $row[(string) $class] = floatval($probability);
            }

            $probabilities[] = $row;
        }

        return $probabilities;
    }

    /**
     * Anomaly scores (higher means more anomalous), or null when the estimator does not score.
     *
     * @param list<list<float|string>> $samples
     * @return list<float>|null
     */
    public function score(array $samples): ?array
    {
        $estimator = $this->estimator();

        if (! $estimator instanceof Scoring) {
            return null;
        }

        return array_map('floatval', array_values($estimator->score(Unlabeled::quick($this->transform($samples)))));
    }

    /**
     * Nearest items of a recommender index.
     *
     * @param list<float|string> $sample
     * @return list<array{key: int|string, distance: float}>
     */
    public function nearest(array $sample, int $k): array
    {
        if ($this->index === null) {
            throw InvalidModelDefinition::because($this->task->value, 'only recommenders have a nearest neighbour index.');
        }

        $vector = $this->transform([$sample])[0];

        [, $labels, $distances] = $this->index->nearest($vector, $k);

        $result = [];

        foreach ($labels as $offset => $label) {
            $result[] = ['key' => is_int($label) || is_string($label) ? $label : (string) $label, 'distance' => $distances[$offset]];
        }

        // The ball tree yields neighbours from a max-heap, so they are ordered here by distance.
        usort($result, static fn(array $a, array $b): int => $a['distance'] <=> $b['distance']);

        return $result;
    }

    public function estimator(): Estimator
    {
        return $this->estimator ?? throw InvalidModelDefinition::because($this->task->value, 'the trained model has no estimator.');
    }
}
