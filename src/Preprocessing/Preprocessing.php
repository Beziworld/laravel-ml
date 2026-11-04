<?php

declare(strict_types=1);

namespace Beziworld\Ml\Preprocessing;

use InvalidArgumentException;

/**
 * Declarative description of the automatic preprocessing pipeline built from a feature schema.
 *
 * - impute: replace missing numeric values with the mean and missing categories with the prior,
 * - normalize: scale numeric, boolean and date columns ("zscore", "minmax", "robust", "maxabs" or null),
 * - oneHot: expand categorical columns into 0/1 columns,
 * - tfidf: weight text token counts with TF-IDF (token counts are always produced for text),
 * - stopWords: words removed from text before tokenising,
 * - ngrams: maximum n-gram length of text tokens (1 = single words).
 */
final readonly class Preprocessing
{
    public const array NORMALIZERS = ['zscore', 'minmax', 'robust', 'maxabs'];

    /**
     * @param list<string> $stopWords
     */
    public function __construct(
        public bool $impute = true,
        public ?string $normalize = 'zscore',
        public bool $oneHot = true,
        public bool $tfidf = true,
        public array $stopWords = [],
        public int $ngrams = 1,
        public ?int $maxVocabulary = null,
    ) {
        if ($normalize !== null && ! in_array($normalize, self::NORMALIZERS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown normalizer "%s", expected one of: %s.', $normalize, implode(', ', self::NORMALIZERS)));
        }

        if ($ngrams < 1) {
            throw new InvalidArgumentException('The n-gram length must be at least 1.');
        }
    }

    public static function defaults(): self
    {
        return new self();
    }

    /**
     * No automatic transformation: samples reach the estimator as cast by the schema.
     */
    public static function none(): self
    {
        return new self(impute: false, normalize: null, oneHot: false, tfidf: false);
    }

    public function withImputation(bool $impute = true): self
    {
        return new self($impute, $this->normalize, $this->oneHot, $this->tfidf, $this->stopWords, $this->ngrams, $this->maxVocabulary);
    }

    public function withNormalization(?string $normalizer = 'zscore'): self
    {
        return new self($this->impute, $normalizer, $this->oneHot, $this->tfidf, $this->stopWords, $this->ngrams, $this->maxVocabulary);
    }

    public function withOneHotEncoding(bool $oneHot = true): self
    {
        return new self($this->impute, $this->normalize, $oneHot, $this->tfidf, $this->stopWords, $this->ngrams, $this->maxVocabulary);
    }

    /**
     * @param list<string>|string $stopWords list of words or a built-in list name ("english", "polish", "french")
     */
    public function withText(bool $tfidf = true, array|string $stopWords = [], int $ngrams = 1, ?int $maxVocabulary = null): self
    {
        $words = is_string($stopWords) ? StopWords::for($stopWords) : $stopWords;

        return new self($this->impute, $this->normalize, $this->oneHot, $tfidf, $words, $ngrams, $maxVocabulary ?? $this->maxVocabulary);
    }

    /**
     * @return array{impute: bool, normalize: string|null, one_hot: bool, tfidf: bool, stop_words: int, ngrams: int}
     */
    public function toArray(): array
    {
        return [
            'impute' => $this->impute,
            'normalize' => $this->normalize,
            'one_hot' => $this->oneHot,
            'tfidf' => $this->tfidf,
            'stop_words' => count($this->stopWords),
            'ngrams' => $this->ngrams,
        ];
    }
}
