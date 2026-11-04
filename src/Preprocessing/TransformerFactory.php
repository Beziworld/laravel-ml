<?php

declare(strict_types=1);

namespace Beziworld\Ml\Preprocessing;

use Beziworld\Ml\Dataset\FeatureSchema;
use Beziworld\Ml\Enums\FeatureType;
use Beziworld\Ml\MlConfig;
use Rubix\ML\Tokenizers\NGram;
use Rubix\ML\Tokenizers\Word;
use Rubix\ML\Transformers\FloatTypeConverter;
use Rubix\ML\Transformers\MaxAbsoluteScaler;
use Rubix\ML\Transformers\MinMaxNormalizer;
use Rubix\ML\Transformers\MissingDataImputer;
use Rubix\ML\Transformers\MultibyteTextNormalizer;
use Rubix\ML\Transformers\OneHotEncoder;
use Rubix\ML\Transformers\RobustStandardizer;
use Rubix\ML\Transformers\StopWordFilter;
use Rubix\ML\Transformers\TfIdfTransformer;
use Rubix\ML\Transformers\Transformer;
use Rubix\ML\Transformers\WordCountVectorizer;
use Rubix\ML\Transformers\ZScaleStandardizer;

/**
 * Builds the Rubix transformer chain that matches a feature schema and a preprocessing description.
 *
 * Numeric, boolean and date columns are imputed and normalised, categorical columns are
 * imputed and one-hot encoded, text columns are lower-cased, filtered from stop words,
 * turned into token counts and weighted with TF-IDF.
 */
final readonly class TransformerFactory
{
    public function __construct(private MlConfig $config) {}

    /**
     * @return list<Transformer>
     */
    public function make(FeatureSchema $schema, Preprocessing $preprocessing): array
    {
        $continuous = [];
        $categorical = [];
        $text = [];

        if ($preprocessing->impute) {
            $continuous[] = new MissingDataImputer();
            $categorical[] = new MissingDataImputer();
        }

        $normalizer = match ($preprocessing->normalize) {
            'zscore' => new ZScaleStandardizer(),
            'minmax' => new MinMaxNormalizer(),
            'robust' => new RobustStandardizer(),
            'maxabs' => new MaxAbsoluteScaler(),
            default => null,
        };

        if ($normalizer !== null) {
            $continuous[] = $normalizer;
        }

        if ($preprocessing->oneHot) {
            $categorical[] = new OneHotEncoder();
        }

        if ($schema->has(FeatureType::Text)) {
            $text[] = new MultibyteTextNormalizer();

            if ($preprocessing->stopWords !== []) {
                $text[] = new StopWordFilter(array_map('mb_strtolower', $preprocessing->stopWords));
            }

            $text[] = new WordCountVectorizer(
                $preprocessing->maxVocabulary ?? $this->config->maxVocabulary,
                $this->config->minDocumentCount,
                $this->config->maxDocumentRatio,
                $preprocessing->ngrams > 1 ? new NGram(1, $preprocessing->ngrams) : new Word(),
            );

            // Rubix 3 treats integers as categorical, token counts must become floats.
            $text[] = new FloatTypeConverter();

            if ($preprocessing->tfidf) {
                $text[] = new TfIdfTransformer();
            }
        }

        $groups = array_filter([
            'continuous' => [$schema->offsetsOf(FeatureType::Numeric, FeatureType::Boolean, FeatureType::Date), $continuous],
            'categorical' => [$schema->offsetsOf(FeatureType::Categorical), $categorical],
            'text' => [$schema->offsetsOf(FeatureType::Text), $text],
        ], static fn(array $group): bool => $group[0] !== [] && $group[1] !== []);

        return $groups === [] ? [] : [new ColumnTransformer($groups)];
    }
}
