<?php

declare(strict_types=1);

namespace Beziworld\Ml\Enums;

/**
 * Kind of machine learning problem a model solves.
 */
enum Task: string
{
    case Classifier = 'classifier';
    case Regressor = 'regressor';
    case Clusterer = 'clusterer';
    case AnomalyDetector = 'anomaly_detector';
    case Recommender = 'recommender';

    /**
     * Whether training requires a label column.
     */
    public function isSupervised(): bool
    {
        return $this === self::Classifier || $this === self::Regressor;
    }

    /**
     * Whether the model can be scored with holdout or k-fold cross validation.
     */
    public function supportsCrossValidation(): bool
    {
        return $this->isSupervised();
    }
}
