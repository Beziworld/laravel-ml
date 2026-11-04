<?php

declare(strict_types=1);

namespace Beziworld\Ml\Association;

use Beziworld\Ml\Data\AssociationRule;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Stringable;

/**
 * Apriori association rule learning (market basket analysis), the php-ml Apriori equivalent.
 *
 *     $apriori = Ml::apriori(support: 0.2, confidence: 0.6)
 *         ->train(Order::with('items')->get()->map(fn ($order) => $order->items->pluck('sku')));
 *
 *     $apriori->rules();                  // Collection of AssociationRule
 *     $apriori->predict(['bread']);       // ['butter', 'milk']
 */
final class Apriori
{
    /** @var list<array{items: list<string>, support: float}> */
    private array $itemsets = [];

    /** @var array<string, float> itemset key => support */
    private array $supports = [];

    /** @var list<AssociationRule> */
    private array $rules = [];

    private int $transactions = 0;

    public function __construct(
        private readonly float $support = 0.1,
        private readonly float $confidence = 0.5,
        private readonly int $maxLength = 0,
    ) {
        if ($support <= 0.0 || $support > 1.0) {
            throw new InvalidArgumentException('Minimum support must be greater than 0 and at most 1.');
        }

        if ($confidence <= 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('Minimum confidence must be greater than 0 and at most 1.');
        }
    }

    /**
     * @param iterable<iterable<mixed>> $transactions each transaction is a list of items (strings, numbers, enums)
     */
    public function train(iterable $transactions): self
    {
        $baskets = [];

        foreach ($transactions as $transaction) {
            $items = [];

            foreach ($transaction as $item) {
                $name = self::item($item);

                if ($name !== '') {
                    $items[$name] = true;
                }
            }

            $baskets[] = $items;
        }

        $this->transactions = count($baskets);
        $this->itemsets = [];
        $this->supports = [];
        $this->rules = [];

        if ($this->transactions === 0) {
            return $this;
        }

        $level = $this->frequentSingles($baskets);
        $length = 1;

        while ($level !== []) {
            foreach ($level as $itemset) {
                $this->itemsets[] = ['items' => $itemset, 'support' => $this->supportOf($itemset)];
            }

            if ($this->maxLength > 0 && $length >= $this->maxLength) {
                break;
            }

            $level = $this->frequent($this->candidates($level), $baskets);
            $length++;
        }

        $this->rules = $this->generateRules();

        return $this;
    }

    /**
     * Frequent itemsets with their support, largest support first.
     *
     * @return Collection<int, array{items: list<string>, support: float}>
     */
    public function frequentItemsets(): Collection
    {
        return collect($this->itemsets)->sortByDesc('support')->values();
    }

    /**
     * Rules sorted by confidence, then lift.
     *
     * @return Collection<int, AssociationRule>
     */
    public function rules(): Collection
    {
        return collect($this->rules);
    }

    /**
     * Items recommended for a basket: consequents of every rule whose antecedent is contained
     * in the basket, ranked by the best rule confidence, without items already in the basket.
     *
     * @param iterable<mixed> $basket
     * @return list<string>
     */
    public function predict(iterable $basket, ?int $limit = null): array
    {
        $present = [];

        foreach ($basket as $item) {
            $present[self::item($item)] = true;
        }

        $scores = [];

        foreach ($this->rules as $rule) {
            foreach ($rule->antecedent as $item) {
                if (! isset($present[$item])) {
                    continue 2;
                }
            }

            foreach ($rule->consequent as $item) {
                if (! isset($present[$item])) {
                    $scores[$item] = max($scores[$item] ?? 0.0, $rule->confidence);
                }
            }
        }

        arsort($scores);

        $items = array_map('strval', array_keys($scores));

        return $limit === null ? $items : array_slice($items, 0, $limit);
    }

    public function transactions(): int
    {
        return $this->transactions;
    }

    /**
     * @param list<array<string, true>> $baskets
     * @return list<list<string>>
     */
    private function frequentSingles(array $baskets): array
    {
        $counts = [];

        foreach ($baskets as $basket) {
            foreach ($basket as $item => $_) {
                $name = strval($item);
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        ksort($counts, SORT_STRING);
        $level = [];

        foreach ($counts as $item => $count) {
            $support = $count / $this->transactions;

            if ($support >= $this->support) {
                $itemset = [strval($item)];
                $this->supports[self::key($itemset)] = $support;
                $level[] = $itemset;
            }
        }

        return $level;
    }

    /**
     * Join step: merge frequent (k-1)-itemsets sharing their first k-2 items, then prune
     * candidates with an infrequent subset.
     *
     * @param list<list<string>> $level
     * @return list<list<string>>
     */
    private function candidates(array $level): array
    {
        $candidates = [];
        $count = count($level);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $level[$i];
                $b = $level[$j];

                if (array_slice($a, 0, -1) !== array_slice($b, 0, -1)) {
                    continue;
                }

                $candidate = $a;
                $candidate[] = $b[count($b) - 1];
                sort($candidate, SORT_STRING);

                if ($this->allSubsetsFrequent($candidate)) {
                    $candidates[self::key($candidate)] = $candidate;
                }
            }
        }

        return array_values($candidates);
    }

    /**
     * @param list<string> $candidate
     */
    private function allSubsetsFrequent(array $candidate): bool
    {
        foreach (array_keys($candidate) as $skip) {
            $subset = $candidate;
            unset($subset[$skip]);

            if (! isset($this->supports[self::key(array_values($subset))])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<list<string>> $candidates
     * @param list<array<string, true>> $baskets
     * @return list<list<string>>
     */
    private function frequent(array $candidates, array $baskets): array
    {
        $frequent = [];

        foreach ($candidates as $candidate) {
            $count = 0;

            foreach ($baskets as $basket) {
                foreach ($candidate as $item) {
                    if (! isset($basket[$item])) {
                        continue 2;
                    }
                }

                $count++;
            }

            $support = $count / $this->transactions;

            if ($support >= $this->support) {
                $this->supports[self::key($candidate)] = $support;
                $frequent[] = $candidate;
            }
        }

        return $frequent;
    }

    /**
     * @return list<AssociationRule>
     */
    private function generateRules(): array
    {
        $rules = [];

        foreach ($this->itemsets as ['items' => $items, 'support' => $support]) {
            $size = count($items);

            if ($size < 2) {
                continue;
            }

            for ($mask = 1; $mask < (1 << $size) - 1; $mask++) {
                $antecedent = [];
                $consequent = [];

                foreach ($items as $bit => $item) {
                    if ((($mask >> $bit) & 1) === 1) {
                        $antecedent[] = $item;
                    } else {
                        $consequent[] = $item;
                    }
                }

                $antecedentSupport = $this->supports[self::key($antecedent)] ?? null;
                $consequentSupport = $this->supports[self::key($consequent)] ?? null;

                if ($antecedentSupport === null || $consequentSupport === null) {
                    continue;
                }

                $confidence = $support / $antecedentSupport;

                if ($confidence + 1e-12 >= $this->confidence) {
                    $rules[] = new AssociationRule(
                        $antecedent,
                        $consequent,
                        round($support, 6),
                        round($confidence, 6),
                        round($confidence / $consequentSupport, 6),
                    );
                }
            }
        }

        usort($rules, static fn(AssociationRule $a, AssociationRule $b): int => [$b->confidence, $b->lift, $b->support] <=> [$a->confidence, $a->lift, $a->support]);

        return $rules;
    }

    /**
     * @param list<string> $itemset
     */
    private function supportOf(array $itemset): float
    {
        return $this->supports[self::key($itemset)] ?? 0.0;
    }

    /**
     * @param list<string> $itemset
     */
    private static function key(array $itemset): string
    {
        return implode("\x1F", $itemset);
    }

    private static function item(mixed $item): string
    {
        return match (true) {
            $item instanceof \BackedEnum => (string) $item->value,
            $item instanceof \UnitEnum => $item->name,
            is_scalar($item), $item instanceof Stringable => trim((string) $item),
            default => '',
        };
    }
}
