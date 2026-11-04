<?php

declare(strict_types=1);

namespace Beziworld\Ml\Dataset;

use BackedEnum;
use Beziworld\Ml\Data\FeatureStatistics;
use Beziworld\Ml\Enums\Task;
use Beziworld\Ml\Exceptions\InvalidDataset;
use Beziworld\Ml\Preprocessing\Preprocessing;
use Beziworld\Ml\Support\Statistics;
use Countable;
use Rubix\ML\Datasets\Dataset as RubixDataset;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\Transformers\Transformer;
use Stringable;
use UnitEnum;

/**
 * Cast samples, optional labels and record keys, ready to be handed to Rubix ML.
 */
final class Dataset implements Countable
{
    /**
     * @param list<list<float|string>> $samples
     * @param list<int|float|string>|null $labels
     * @param list<int|string> $keys
     */
    public function __construct(
        private readonly FeatureSchema $schema,
        private readonly array $samples,
        private readonly ?array $labels = null,
        private readonly ?string $labelName = null,
        private readonly ?Preprocessing $preprocessing = null,
        private readonly array $keys = [],
    ) {
        if ($labels !== null && count($labels) !== count($samples)) {
            throw InvalidDataset::because('the number of labels does not match the number of samples.');
        }

        if ($keys !== [] && count($keys) !== count($samples)) {
            throw InvalidDataset::because('the number of keys does not match the number of samples.');
        }
    }

    public function schema(): FeatureSchema
    {
        return $this->schema;
    }

    /**
     * @return list<list<float|string>>
     */
    public function samples(): array
    {
        return $this->samples;
    }

    /**
     * @return list<int|float|string>
     */
    public function labels(): array
    {
        return $this->labels ?? [];
    }

    public function hasLabels(): bool
    {
        return $this->labels !== null;
    }

    public function labelName(): ?string
    {
        return $this->labelName;
    }

    /**
     * Record keys (e.g. primary keys) in sample order; empty when no key column was given.
     *
     * @return list<int|string>
     */
    public function keys(): array
    {
        return $this->keys;
    }

    public function preprocessing(): ?Preprocessing
    {
        return $this->preprocessing;
    }

    public function withPreprocessing(?Preprocessing $preprocessing): self
    {
        return new self($this->schema, $this->samples, $this->labels, $this->labelName, $preprocessing, $this->keys);
    }

    public function count(): int
    {
        return count($this->samples);
    }

    public function isEmpty(): bool
    {
        return $this->samples === [];
    }

    /**
     * Keep only the given sample offsets.
     *
     * @param list<int> $offsets
     */
    public function subset(array $offsets): self
    {
        $pick = static fn(array $values): array => array_map(static fn(int $offset): mixed => $values[$offset], $offsets);

        return new self(
            $this->schema,
            $pick($this->samples),
            $this->labels !== null ? $pick($this->labels) : null,
            $this->labelName,
            $this->preprocessing,
            $this->keys !== [] ? $pick($this->keys) : [],
        );
    }

    /**
     * Labels cast for the given task: strings for classes, floats for regression targets,
     * 0/1 integers for anomaly flags.
     *
     * @return list<int|float|string>
     */
    public function labelsFor(Task $task): array
    {
        if ($this->labels === null) {
            throw InvalidDataset::because(sprintf('a %s needs a label column.', str_replace('_', ' ', $task->value)));
        }

        return array_map(static fn(int|float|string $label): int|float|string => self::castLabel($label, $task), $this->labels);
    }

    /**
     * Build the Rubix dataset; labeled when the task is supervised (or when labels exist and $task is null).
     */
    public function toRubix(?Task $task = null): RubixDataset
    {
        $this->ensureNotEmpty();

        if ($task === Task::Classifier || $task === Task::Regressor || ($task === null && $this->labels !== null)) {
            return new Labeled($this->samples, $this->labelsFor($task ?? Task::Classifier));
        }

        return new Unlabeled($this->samples);
    }

    /**
     * Apply transformers (fitting them first) and return the transformed Rubix dataset.
     *
     * @param list<Transformer> $transformers
     */
    public function transform(array $transformers, ?Task $task = null): RubixDataset
    {
        $dataset = $this->toRubix($task);

        foreach ($transformers as $transformer) {
            $dataset->apply($transformer);
        }

        return $dataset;
    }

    /**
     * SHA-256 fingerprint of the samples and labels, stored with every trained version.
     */
    public function hash(): string
    {
        $context = hash_init('sha256');

        hash_update($context, json_encode($this->schema->toArray(), JSON_THROW_ON_ERROR));

        foreach ($this->samples as $offset => $sample) {
            hash_update($context, serialize($sample));
            hash_update($context, serialize($this->labels[$offset] ?? null));
        }

        return hash_final($context);
    }

    /**
     * @return list<FeatureStatistics>
     */
    public function statistics(): array
    {
        return Statistics::describe($this->schema, $this->samples);
    }

    /**
     * Rows keyed by feature name (and the label), handy for debugging and exports.
     *
     * @return list<array<string, float|int|string|null>>
     */
    public function toArray(): array
    {
        $names = $this->schema->names();
        $rows = [];

        foreach ($this->samples as $offset => $sample) {
            $row = array_combine($names, array_map(static fn(float|string $value): float|string|null => is_float($value) && is_nan($value) ? null : $value, $sample));

            if ($this->labels !== null) {
                $row[$this->labelName ?? 'label'] = $this->labels[$offset];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Normalise a raw label value read from a record.
     */
    public static function normalizeLabel(mixed $label): int|float|string
    {
        return match (true) {
            is_bool($label) => $label ? 'true' : 'false',
            is_int($label), is_float($label), is_string($label) => $label,
            $label instanceof BackedEnum => $label->value,
            $label instanceof UnitEnum => $label->name,
            $label instanceof Stringable => (string) $label,
            $label === null => throw InvalidDataset::because('a label is missing (null). Filter out unlabeled records first.'),
            default => throw InvalidDataset::because(sprintf('a label must be a scalar, %s given.', get_debug_type($label))),
        };
    }

    private static function castLabel(int|float|string $label, Task $task): int|float|string
    {
        return match ($task) {
            Task::Regressor => is_numeric($label)
                ? (float) $label
                : throw InvalidDataset::because(sprintf('a regressor needs numeric labels, "%s" given.', $label)),
            Task::AnomalyDetector => in_array(is_string($label) ? strtolower($label) : $label, [1, 1.0, '1', 'true', 'yes', 'anomaly', 'outlier'], true) ? 1 : 0,
            default => (string) $label,
        };
    }

    private function ensureNotEmpty(): void
    {
        if ($this->samples === []) {
            throw InvalidDataset::because('the dataset is empty.');
        }
    }
}
