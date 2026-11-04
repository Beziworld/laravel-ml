<?php

declare(strict_types=1);

namespace Beziworld\Ml\Models;

use Beziworld\Ml\Enums\FeatureType;
use Beziworld\Ml\Enums\Task;
use Beziworld\Ml\Preprocessing\Preprocessing;
use Rubix\ML\Estimator;
use Rubix\ML\Learner;

/**
 * Text classifier (spam filter, sentiment, ticket routing): the text() attribute is lower-cased,
 * filtered from stop words, vectorised into token counts and weighted with TF-IDF before a
 * softmax (multinomial logistic regression) classifier is trained.
 */
abstract class TextClassificationModel extends MachineLearningModel
{
    /**
     * Attribute holding the text.
     */
    abstract public function text(): string;

    public function task(): Task
    {
        return Task::Classifier;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function features(): array
    {
        return [$this->text() => FeatureType::Text];
    }

    /**
     * Built-in stop word list name ("english", "polish", "french"), a list of words, or null.
     *
     * @return list<string>|string|null
     */
    public function stopWords(): array|string|null
    {
        return null;
    }

    /**
     * Maximum n-gram length (1 = single words, 2 = words and word pairs).
     */
    public function ngrams(): int
    {
        return 1;
    }

    public function preprocessing(): ?Preprocessing
    {
        return Preprocessing::defaults()->withText(true, $this->stopWords() ?? [], $this->ngrams());
    }

    public function estimator(): Learner&Estimator
    {
        return Estimators::classifier('softmax');
    }
}
