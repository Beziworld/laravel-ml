<?php

declare(strict_types=1);

use Beziworld\Ml\Association\Apriori;
use Beziworld\Ml\Data\AssociationRule;
use Beziworld\Ml\Facades\Ml;
use Beziworld\Ml\Tests\Fixtures\Synthetic;

it('finds frequent itemsets with their support', function (): void {
    $apriori = Ml::apriori(support: 0.3, confidence: 0.6)->train(Synthetic::baskets());
    $itemsets = $apriori->frequentItemsets();

    expect($apriori->transactions())->toBe(10)
        ->and($itemsets->first())->toBe(['items' => ['bread'], 'support' => 0.7])
        ->and($itemsets->firstWhere('items', ['bread', 'butter'])['support'] ?? null)->toBe(0.5)
        ->and($itemsets->firstWhere('items', ['jam']))->toBeNull();
});

it('derives rules with confidence and lift', function (): void {
    $rules = (new Apriori(0.3, 0.6))->train(Synthetic::baskets())->rules();
    $butter = $rules->first(fn(AssociationRule $rule): bool => $rule->antecedent === ['butter'] && $rule->consequent === ['bread']);
    $chips = $rules->first(fn(AssociationRule $rule): bool => $rule->antecedent === ['chips'] && $rule->consequent === ['beer']);

    expect($butter)->not->toBeNull()
        ->and($butter?->confidence)->toBe(1.0)
        ->and($butter?->lift)->toBe(round(1 / 0.7, 6))
        ->and($chips?->support)->toBe(0.3)
        ->and((string) $butter)->toBe('{butter} => {bread}')
        ->and($rules->first()?->confidence)->toBe(1.0);
});

it('recommends items for a basket', function (): void {
    $apriori = Ml::apriori(0.2, 0.5)->train(collect(Synthetic::baskets())->map(fn(array $items) => collect($items)));

    expect($apriori->predict(['butter']))->toContain('bread')
        ->and($apriori->predict(['chips']))->toBe(['beer'])
        ->and($apriori->predict(['milk', 'cereal'], limit: 1))->toHaveCount(1)
        ->and($apriori->predict(['caviar']))->toBe([]);
});

it('limits the itemset length', function (): void {
    $apriori = Ml::apriori(0.1, 0.1, maxLength: 1)->train(Synthetic::baskets());

    expect($apriori->frequentItemsets()->every(fn(array $itemset): bool => count($itemset['items']) === 1))->toBeTrue()
        ->and($apriori->rules())->toBeEmpty();
});

it('validates support and confidence', function (): void {
    expect(fn() => new Apriori(0.0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new Apriori(0.5, 1.5))->toThrow(InvalidArgumentException::class);
});
