<?php

declare(strict_types=1);

use Beziworld\Ml\Dataset\FeatureSchema;
use Beziworld\Ml\Preprocessing\ColumnTransformer;
use Beziworld\Ml\Preprocessing\Preprocessing;
use Beziworld\Ml\Preprocessing\StopWords;
use Beziworld\Ml\Preprocessing\TransformerFactory;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\Transformers\OneHotEncoder;
use Rubix\ML\Transformers\ZScaleStandardizer;

it('turns text into TF-IDF floats next to other features', function (): void {
    $schema = FeatureSchema::from(['message' => 'text', 'length' => 'numeric']);
    $transformers = app(TransformerFactory::class)->make($schema, Preprocessing::defaults()->withText(true, 'english'));
    $dataset = Unlabeled::quick([['The free prize', 3.0], ['A meeting today', 5.0]]);

    foreach ($transformers as $transformer) {
        $dataset->apply($transformer);
    }

    $sample = $dataset->sample(0);

    expect($transformers)->toHaveCount(1)
        ->and($transformers[0]->groups())->toBe(['continuous', 'text'])
        ->and($sample)->toHaveCount(1 + 4)
        ->and(array_filter($sample, 'is_float'))->toHaveCount(5)
        ->and($sample[0])->toBe(-1.0);
});

it('returns no transformers when preprocessing is disabled', function (): void {
    expect(app(TransformerFactory::class)->make(FeatureSchema::from(['a' => 'numeric', 'b' => 'categorical']), Preprocessing::none()))->toBe([]);
});

it('scopes transformers to column groups and passes other columns through', function (): void {
    $transformer = new ColumnTransformer([
        'numbers' => [[1], [new ZScaleStandardizer()]],
        'categories' => [[2], [new OneHotEncoder()]],
    ]);
    $dataset = Unlabeled::quick([['keep', 1.0, 'x'], ['me', 3.0, 'y']])->apply($transformer);

    expect($dataset->samples())->toBe([['keep', -1.0, 1.0, 0.0], ['me', 1.0, 0.0, 1.0]])
        ->and((string) $transformer)->toBe('Column Transformer (numbers, categories)');
});

it('validates normalizers and n-grams', function (): void {
    expect(fn() => new Preprocessing(normalize: 'log'))->toThrow(InvalidArgumentException::class, 'Unknown normalizer')
        ->and(fn() => new Preprocessing(ngrams: 0))->toThrow(InvalidArgumentException::class);
});

it('ships English, Polish and French stop words', function (): void {
    expect(StopWords::for('en'))->toContain('the')
        ->and(StopWords::polish())->toContain('się')
        ->and(StopWords::french())->toContain('les')
        ->and(fn() => StopWords::for('klingon'))->toThrow(InvalidArgumentException::class);
});
