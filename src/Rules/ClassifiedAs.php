<?php

declare(strict_types=1);

namespace Beziworld\Ml\Rules;

use Beziworld\Ml\Data\Prediction;
use Beziworld\Ml\Models\MachineLearningModel;

/**
 * Passes when a classifier predicts one of the given classes, optionally with a minimum confidence.
 *
 *     'message' => ['required', new ClassifiedAs(SpamFilter::class, 'ham')],
 */
final class ClassifiedAs extends PredictionRule
{
    /** @var list<int|string|bool> */
    private array $classes;

    /**
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     * @param int|string|bool|list<int|string|bool> $classes
     */
    public function __construct(
        MachineLearningModel|string $model,
        int|string|bool|array $classes,
        private readonly ?float $minConfidence = null,
    ) {
        parent::__construct($model);

        $this->classes = is_array($classes) ? $classes : [$classes];
    }

    protected function passes(Prediction $prediction): bool
    {
        if (! $prediction->is(...$this->classes)) {
            return false;
        }

        return $this->minConfidence === null || ($prediction->confidence ?? 0.0) >= $this->minConfidence;
    }

    protected function message(): string
    {
        return 'The :attribute was not recognised as valid.';
    }
}
