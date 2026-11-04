<?php

declare(strict_types=1);

namespace Beziworld\Ml\Dataset;

use Beziworld\Ml\Enums\Task;
use Beziworld\Ml\Exceptions\InvalidDataset;
use Beziworld\Ml\Preprocessing\Preprocessing;
use Beziworld\Ml\Preprocessing\TransformerFactory;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Rubix\ML\Datasets\Dataset as RubixDataset;
use SplFileObject;

/**
 * Fluent builder that streams records from Eloquent, collections, arrays or CSV files into a Dataset.
 *
 *     Ml::dataset()
 *         ->fromQuery(User::query(), features: ['age', 'plan', 'signed_up_at'], label: 'churned')
 *         ->normalize('minmax')
 *         ->get();
 */
final class DatasetBuilder
{
    /** Number of leading records used to infer undeclared feature types. */
    private const int INFERENCE_WINDOW = 100;

    /** @var (Closure(): iterable<mixed>)|null */
    private ?Closure $source = null;

    /** @var array<array-key, mixed> */
    private array $features = [];

    /** @var string|(Closure(mixed): mixed)|null */
    private string|Closure|null $label = null;

    /** @var string|(Closure(mixed): mixed)|null */
    private string|Closure|null $key = null;

    private ?Preprocessing $preprocessing = null;

    private ?int $limit = null;

    public function __construct(private readonly TransformerFactory $transformers) {}

    /**
     * Stream an Eloquent or query builder with lazy(); only one chunk of models is held in memory.
     *
     * @param EloquentBuilder<covariant Model>|QueryBuilder|Relation<covariant Model, covariant Model, mixed> $query
     * @param array<array-key, mixed> $features
     * @param string|(Closure(mixed): mixed)|null $label
     * @param string|(Closure(mixed): mixed)|null $key
     */
    public function fromQuery(
        EloquentBuilder|QueryBuilder|Relation $query,
        array $features = [],
        string|Closure|null $label = null,
        int $chunk = 1000,
        string|Closure|null $key = null,
    ): self {
        $builder = $query instanceof Relation ? $query->getQuery() : $query;

        $this->source = static function () use ($builder, $chunk): iterable {
            if ($builder instanceof QueryBuilder && $builder->orders === null && $builder->unionOrders === null) {
                return $builder->cursor();
            }

            return $builder->lazy($chunk);
        };

        if ($key === null && $builder instanceof EloquentBuilder) {
            $key = static fn(Model $model): mixed => $model->getKey();
        }

        return $this->configure($features, $label, $key);
    }

    /**
     * Use any iterable of arrays, objects or Eloquent models (collections, lazy collections, generators).
     *
     * @param iterable<mixed> $records
     * @param array<array-key, mixed> $features
     * @param string|(Closure(mixed): mixed)|null $label
     * @param string|(Closure(mixed): mixed)|null $key
     */
    public function fromCollection(iterable $records, array $features = [], string|Closure|null $label = null, string|Closure|null $key = null): self
    {
        $this->source = static fn(): iterable => $records;

        return $this->configure($features, $label, $key);
    }

    /**
     * Stream a CSV file row by row. With a header row, features refer to column names,
     * otherwise to zero-based column numbers.
     *
     * @param array<array-key, mixed> $features
     * @param string|(Closure(mixed): mixed)|null $label
     * @param string|(Closure(mixed): mixed)|null $key
     */
    public function fromCsv(
        string $path,
        array $features = [],
        string|Closure|null $label = null,
        string $delimiter = ',',
        bool $header = true,
        string $enclosure = '"',
        string|Closure|null $key = null,
    ): self {
        if (! is_file($path) || ! is_readable($path)) {
            throw InvalidDataset::because(sprintf('the CSV file "%s" does not exist or is not readable.', $path));
        }

        $this->source = static fn(): Generator => self::readCsv($path, $delimiter, $enclosure, $header);

        return $this->configure($features, $label, $key);
    }

    /**
     * php-ml style input: a list of numeric or string vectors with optional labels.
     *
     * @param list<list<mixed>> $samples
     * @param list<mixed>|null $labels
     * @param list<string>|null $names feature names, defaults to f0, f1, ...
     */
    public function fromArray(array $samples, ?array $labels = null, ?array $names = null): self
    {
        $width = count($samples[0] ?? []);
        $names ??= array_map(static fn(int $i): string => 'f' . $i, $width > 0 ? range(0, $width - 1) : []);

        $records = [];

        foreach ($samples as $offset => $sample) {
            if (count($sample) !== count($names)) {
                throw InvalidDataset::because(sprintf('sample %d has %d values, %d expected.', $offset, count($sample), count($names)));
            }

            $record = array_combine($names, $sample);

            if ($labels !== null) {
                $record['__label'] = $labels[$offset] ?? null;
            }

            $records[] = $record;
        }

        $this->source = static fn(): array => $records;

        return $this->configure($names, $labels !== null ? '__label' : null, null);
    }

    /**
     * @param array<array-key, mixed> $features
     */
    public function features(array $features): self
    {
        $this->features = $features;

        return $this;
    }

    /**
     * @param string|(Closure(mixed): mixed)|null $label
     */
    public function label(string|Closure|null $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @param string|(Closure(mixed): mixed)|null $key
     */
    public function key(string|Closure|null $key): self
    {
        $this->key = $key;

        return $this;
    }

    public function limit(?int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    public function preprocessing(Preprocessing $preprocessing): self
    {
        $this->preprocessing = $preprocessing;

        return $this;
    }

    public function impute(bool $impute = true): self
    {
        $this->preprocessing = $this->current()->withImputation($impute);

        return $this;
    }

    /**
     * @param string|null $normalizer "zscore", "minmax", "robust", "maxabs" or null
     */
    public function normalize(?string $normalizer = 'zscore'): self
    {
        $this->preprocessing = $this->current()->withNormalization($normalizer);

        return $this;
    }

    public function oneHot(bool $oneHot = true): self
    {
        $this->preprocessing = $this->current()->withOneHotEncoding($oneHot);

        return $this;
    }

    /**
     * @param list<string>|string $stopWords
     */
    public function text(bool $tfidf = true, array|string $stopWords = [], int $ngrams = 1, ?int $maxVocabulary = null): self
    {
        $this->preprocessing = $this->current()->withText($tfidf, $stopWords, $ngrams, $maxVocabulary);

        return $this;
    }

    /**
     * Read every record and return the cast dataset.
     */
    public function get(): Dataset
    {
        if ($this->source === null) {
            throw InvalidDataset::because('no source given; call fromQuery(), fromCollection(), fromCsv() or fromArray() first.');
        }

        if ($this->features === []) {
            throw InvalidDataset::because('no features given.');
        }

        $schema = FeatureSchema::from($this->features);
        $buffer = [];
        $samples = [];
        $label = $this->label;
        $key = $this->key;
        $labels = $label !== null ? [] : null;
        $keys = [];
        $read = 0;

        foreach (($this->source)() as $record) {
            if ($this->limit !== null && $read >= $this->limit) {
                break;
            }

            $read++;

            if ($labels !== null && $label !== null) {
                $labels[] = Dataset::normalizeLabel($this->value($record, $label));
            }

            if ($key !== null) {
                $value = $this->value($record, $key);
                $keys[] = is_int($value) || is_string($value) ? $value : (is_scalar($value) ? (string) $value : $read - 1);
            }

            if ($schema->isResolved()) {
                $samples[] = $schema->extract($record);

                continue;
            }

            $buffer[] = $schema->read($record);

            if (count($buffer) >= self::INFERENCE_WINDOW) {
                $schema = $schema->resolve($buffer);
                array_push($samples, ...array_map($schema->cast(...), $buffer));
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $schema = $schema->resolve($buffer);
            array_push($samples, ...array_map($schema->cast(...), $buffer));
        }

        if (! $schema->isResolved()) {
            $schema = $schema->resolve([]);
        }

        $labelName = is_string($this->label) ? $this->label : ($this->label !== null ? 'label' : null);

        return new Dataset($schema, $samples, $labels, $labelName === '__label' ? 'label' : $labelName, $this->preprocessing, $keys);
    }

    /**
     * Build the dataset and apply the configured preprocessing, returning a Rubix dataset.
     */
    public function toRubix(?Task $task = null): RubixDataset
    {
        $dataset = $this->get();

        return $dataset->transform($this->transformers->make($dataset->schema(), $this->current()), $task);
    }

    /**
     * @param array<array-key, mixed> $features
     * @param string|(Closure(mixed): mixed)|null $label
     * @param string|(Closure(mixed): mixed)|null $key
     */
    private function configure(array $features, string|Closure|null $label, string|Closure|null $key): self
    {
        if ($features !== []) {
            $this->features = $features;
        }

        $this->label = $label ?? $this->label;
        $this->key = $key ?? $this->key;

        return $this;
    }

    private function current(): Preprocessing
    {
        return $this->preprocessing ?? Preprocessing::defaults();
    }

    /**
     * @param string|(Closure(mixed): mixed) $accessor
     */
    private function value(mixed $record, string|Closure $accessor): mixed
    {
        return $accessor instanceof Closure ? $accessor($record) : data_get($record, $accessor);
    }

    /**
     * @return Generator<int, array<array-key, string|null>>
     */
    private static function readCsv(string $path, string $delimiter, string $enclosure, bool $header): Generator
    {
        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($delimiter, $enclosure, '');

        $columns = null;

        foreach ($file as $row) {
            if (! is_array($row) || $row === [null]) {
                continue;
            }

            if ($header && $columns === null) {
                $columns = array_map(static fn(mixed $column): string => trim((string) $column, " \t\n\r\0\x0B\u{FEFF}"), $row);

                continue;
            }

            if ($columns === null) {
                yield $row;

                continue;
            }

            $values = array_slice(array_pad($row, count($columns), null), 0, count($columns));

            yield array_combine($columns, $values);
        }
    }
}
