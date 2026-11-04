<?php

declare(strict_types=1);

namespace Beziworld\Ml\Training;

use InvalidArgumentException;

/**
 * Cross validation strategy used to score a model before the final fit.
 */
final readonly class Validation
{
    public const string HOLDOUT = 'holdout';

    public const string KFOLD = 'kfold';

    public const string NONE = 'none';

    public function __construct(
        public string $strategy = self::HOLDOUT,
        public float $ratio = 0.2,
        public int $folds = 5,
    ) {
        if (! in_array($strategy, [self::HOLDOUT, self::KFOLD, self::NONE], true)) {
            throw new InvalidArgumentException(sprintf('Unknown validation strategy "%s".', $strategy));
        }

        if ($ratio <= 0.0 || $ratio >= 1.0) {
            throw new InvalidArgumentException('The holdout ratio must be between 0 and 1.');
        }

        if ($folds < 2) {
            throw new InvalidArgumentException('K-fold cross validation needs at least 2 folds.');
        }
    }

    /**
     * Hold out a share of the samples (stratified for classifiers) as the test set.
     */
    public static function holdout(float $ratio = 0.2): self
    {
        return new self(self::HOLDOUT, $ratio);
    }

    /**
     * Score the model on k rotating folds and aggregate the out-of-fold predictions.
     */
    public static function kFold(int $folds = 5): self
    {
        return new self(self::KFOLD, folds: $folds);
    }

    /**
     * Skip validation and fit on all samples.
     */
    public static function none(): self
    {
        return new self(self::NONE);
    }

    public function describe(): string
    {
        return match ($this->strategy) {
            self::HOLDOUT => sprintf('holdout (%d%% test)', (int) round($this->ratio * 100)),
            self::KFOLD => sprintf('%d-fold', $this->folds),
            default => 'none',
        };
    }
}
