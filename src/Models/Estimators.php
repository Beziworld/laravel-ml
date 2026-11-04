<?php

declare(strict_types=1);

namespace Beziworld\Ml\Models;

use Beziworld\Ml\Enums\Task;
use Beziworld\Ml\Support\Rubix;
use InvalidArgumentException;
use Rubix\ML\AnomalyDetectors\GaussianMLE;
use Rubix\ML\AnomalyDetectors\IsolationForest;
use Rubix\ML\AnomalyDetectors\LocalOutlierFactor;
use Rubix\ML\AnomalyDetectors\Loda;
use Rubix\ML\AnomalyDetectors\RobustZScore;
use Rubix\ML\BootstrapAggregator;
use Rubix\ML\Classifiers\AdaBoost;
use Rubix\ML\Classifiers\ClassificationTree;
use Rubix\ML\Classifiers\GaussianNB;
use Rubix\ML\Classifiers\KNearestNeighbors;
use Rubix\ML\Classifiers\LogisticRegression;
use Rubix\ML\Classifiers\MultilayerPerceptron;
use Rubix\ML\Classifiers\NaiveBayes;
use Rubix\ML\Classifiers\RandomForest;
use Rubix\ML\Classifiers\SoftmaxClassifier;
use Rubix\ML\Clusterers\DBSCAN;
use Rubix\ML\Clusterers\FuzzyCMeans;
use Rubix\ML\Clusterers\GaussianMixture;
use Rubix\ML\Clusterers\KMeans;
use Rubix\ML\Clusterers\MeanShift;
use Rubix\ML\Estimator;
use Rubix\ML\Learner;
use Rubix\ML\NeuralNet\ActivationFunctions\ReLU;
use Rubix\ML\NeuralNet\Layers\Activation;
use Rubix\ML\NeuralNet\Layers\Dense;
use Rubix\ML\NeuralNet\Layers\Hidden;
use Rubix\ML\Regressors\Adaline;
use Rubix\ML\Regressors\GradientBoost;
use Rubix\ML\Regressors\KNNRegressor;
use Rubix\ML\Regressors\MLPRegressor;
use Rubix\ML\Regressors\RegressionTree;
use Rubix\ML\Regressors\Ridge;

/**
 * Named presets for the Rubix estimators that cover php-ml's algorithms.
 *
 *     Estimators::classifier('random_forest');
 *     Estimators::neuralNetwork([32, 16]);
 */
final class Estimators
{
    public const array CLASSIFIERS = ['knn', 'naive_bayes', 'gaussian_nb', 'tree', 'random_forest', 'adaboost', 'bagging', 'logistic', 'softmax', 'mlp'];

    public const array REGRESSORS = ['ridge', 'least_squares', 'knn', 'tree', 'gradient_boost', 'adaline', 'mlp'];

    public const array CLUSTERERS = ['kmeans', 'dbscan', 'fuzzy_cmeans', 'gaussian_mixture', 'mean_shift'];

    public const array ANOMALY_DETECTORS = ['isolation_forest', 'local_outlier_factor', 'loda', 'gaussian_mle', 'robust_zscore'];

    /**
     * Default estimator of a task preset.
     */
    public static function defaultFor(Task $task): Learner&Estimator
    {
        return match ($task) {
            Task::Classifier => self::classifier('random_forest'),
            Task::Regressor => self::regressor('gradient_boost'),
            Task::Clusterer => self::clusterer('kmeans'),
            Task::AnomalyDetector => self::anomalyDetector('isolation_forest'),
            Task::Recommender => new KNearestNeighbors(),
        };
    }

    public static function classifier(string $name = 'random_forest'): Learner&Estimator
    {
        return Rubix::quietly(static fn(): Learner&Estimator => match ($name) {
            'knn' => new KNearestNeighbors(5, true),
            'naive_bayes' => new NaiveBayes(),
            'gaussian_nb' => new GaussianNB(),
            'tree' => new ClassificationTree(10),
            'random_forest' => new RandomForest(new ClassificationTree(10), 100),
            'adaboost' => new AdaBoost(new ClassificationTree(1)),
            'bagging' => new BootstrapAggregator(new ClassificationTree(10), 50),
            'logistic' => new LogisticRegression(),
            'softmax' => new SoftmaxClassifier(),
            'mlp' => self::neuralNetwork(),
            default => throw self::unknown('classifier', $name, self::CLASSIFIERS),
        });
    }

    public static function regressor(string $name = 'gradient_boost'): Learner&Estimator
    {
        return Rubix::quietly(static fn(): Learner&Estimator => match ($name) {
            'ridge' => new Ridge(1.0),
            'least_squares' => new Ridge(1e-6),
            'knn' => new KNNRegressor(5, true),
            'tree' => new RegressionTree(10),
            'gradient_boost' => new GradientBoost(new RegressionTree(4), 0.1, 0.8, 200),
            'adaline' => new Adaline(),
            'mlp' => self::neuralNetwork(task: Task::Regressor),
            default => throw self::unknown('regressor', $name, self::REGRESSORS),
        });
    }

    /**
     * Clusterer preset; mean_shift uses a radius of 1.0, which suits z-score normalised features.
     */
    public static function clusterer(string $name = 'kmeans', int $clusters = 3): Learner&Estimator
    {
        return match ($name) {
            'kmeans' => new KMeans($clusters),
            'dbscan' => new DBSCAN(),
            'fuzzy_cmeans' => new FuzzyCMeans($clusters),
            'gaussian_mixture' => new GaussianMixture($clusters),
            'mean_shift' => new MeanShift(1.0),
            default => throw self::unknown('clusterer', $name, self::CLUSTERERS),
        };
    }

    public static function anomalyDetector(string $name = 'isolation_forest', ?float $contamination = null): Learner&Estimator
    {
        return Rubix::quietly(static fn(): Learner&Estimator => match ($name) {
            'isolation_forest' => new IsolationForest(100, null, $contamination),
            'local_outlier_factor' => new LocalOutlierFactor(20, $contamination),
            'loda' => new Loda($contamination ?? 0.1),
            'gaussian_mle' => new GaussianMLE($contamination ?? 0.1),
            'robust_zscore' => new RobustZScore(),
            default => throw self::unknown('anomaly detector', $name, self::ANOMALY_DETECTORS),
        });
    }

    /**
     * Multilayer perceptron with ReLU hidden layers (php-ml's MLPClassifier equivalent).
     *
     * @param list<int> $hidden neurons per hidden layer
     */
    public static function neuralNetwork(array $hidden = [32, 16], int $epochs = 300, Task $task = Task::Classifier): Learner&Estimator
    {
        /** @var list<Hidden> $layers */
        $layers = [];

        foreach ($hidden as $neurons) {
            $layers[] = new Dense($neurons);
            $layers[] = new Activation(new ReLU());
        }

        return Rubix::quietly(static fn(): Learner&Estimator => $task === Task::Regressor
            ? new MLPRegressor($layers, 32, epochs: $epochs)
            : new MultilayerPerceptron($layers, 32, epochs: $epochs));
    }

    /**
     * @param list<string> $known
     */
    private static function unknown(string $kind, string $name, array $known): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('Unknown %s preset "%s", expected one of: %s.', $kind, $name, implode(', ', $known)));
    }
}
