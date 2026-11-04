<?php

declare(strict_types=1);

namespace Beziworld\Ml\Data;

use JsonSerializable;

/**
 * Association rule "antecedent => consequent" found by Apriori.
 *
 * support: share of transactions containing both sides; confidence: P(consequent | antecedent);
 * lift: confidence divided by the support of the consequent (above 1 means a positive association).
 */
final readonly class AssociationRule implements JsonSerializable
{
    /**
     * @param list<string> $antecedent
     * @param list<string> $consequent
     */
    public function __construct(
        public array $antecedent,
        public array $consequent,
        public float $support,
        public float $confidence,
        public float $lift,
    ) {}

    /**
     * @return array{antecedent: list<string>, consequent: list<string>, support: float, confidence: float, lift: float}
     */
    public function toArray(): array
    {
        return [
            'antecedent' => $this->antecedent,
            'consequent' => $this->consequent,
            'support' => $this->support,
            'confidence' => $this->confidence,
            'lift' => $this->lift,
        ];
    }

    /**
     * @return array{antecedent: list<string>, consequent: list<string>, support: float, confidence: float, lift: float}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return sprintf('{%s} => {%s}', implode(', ', $this->antecedent), implode(', ', $this->consequent));
    }
}
