<?php

declare(strict_types=1);

namespace Beziworld\Ml\Preprocessing;

use Rubix\ML\Datasets\Dataset;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\DataType;
use Rubix\ML\Persistable;
use Rubix\ML\Traits\AutotrackRevisions;
use Rubix\ML\Transformers\Pipeline;
use Rubix\ML\Transformers\Stateful;
use Rubix\ML\Transformers\Transformer;

/**
 * Applies separate transformer chains to separate groups of columns.
 *
 * Rubix transformers such as OneHotEncoder or WordCountVectorizer act on every categorical
 * column of a dataset. This transformer scopes them, e.g. one-hot encoding for categorical
 * columns and TF-IDF for text columns of the same sample. The output of each group is
 * appended in group order; columns that belong to no group are kept in front of them.
 */
final class ColumnTransformer implements Persistable, Stateful
{
    use AutotrackRevisions;

    /**
     * @var list<array{columns: list<int>, pipeline: Pipeline, name: string}>
     */
    private array $groups = [];

    /**
     * @param array<string, array{0: list<int>, 1: list<Transformer>}> $groups name => [column offsets, transformers]
     */
    public function __construct(array $groups)
    {
        foreach ($groups as $name => [$columns, $transformers]) {
            if ($columns === []) {
                continue;
            }

            $this->groups[] = ['columns' => $columns, 'pipeline' => new Pipeline($transformers), 'name' => $name];
        }
    }

    /**
     * @return list<DataType>
     */
    public function compatibility(): array
    {
        return DataType::all();
    }

    public function fit(Dataset $dataset): void
    {
        $samples = $dataset->samples();

        foreach ($this->groups as $group) {
            $group['pipeline']->fit(Unlabeled::quick($this->slice($samples, $group['columns'])));
        }
    }

    public function fitted(): bool
    {
        foreach ($this->groups as $group) {
            if (! $group['pipeline']->fitted()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<list<mixed>> $samples
     */
    public function transform(array &$samples): void
    {
        $outputs = [];
        $grouped = [];

        foreach ($this->groups as $index => $group) {
            $slice = $this->slice($samples, $group['columns']);
            $group['pipeline']->transform($slice);
            $outputs[$index] = $slice;

            foreach ($group['columns'] as $column) {
                $grouped[$column] = true;
            }
        }

        foreach ($samples as $row => &$sample) {
            $result = [];

            foreach ($sample as $column => $value) {
                if (! isset($grouped[$column])) {
                    $result[] = $value;
                }
            }

            foreach ($outputs as $output) {
                foreach ($output[$row] as $value) {
                    $result[] = $value;
                }
            }

            $sample = $result;
        }

        unset($sample);
    }

    /**
     * Names of the column groups this transformer handles.
     *
     * @return list<string>
     */
    public function groups(): array
    {
        return array_map(static fn(array $group): string => $group['name'], $this->groups);
    }

    public function __toString(): string
    {
        return 'Column Transformer (' . implode(', ', $this->groups()) . ')';
    }

    /**
     * @param array<array-key, list<mixed>> $samples
     * @param list<int> $columns
     * @return list<list<mixed>>
     */
    private function slice(array $samples, array $columns): array
    {
        $slice = [];

        foreach ($samples as $sample) {
            $row = [];

            foreach ($columns as $column) {
                $row[] = $sample[$column];
            }

            $slice[] = $row;
        }

        return $slice;
    }
}
