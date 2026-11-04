<?php

declare(strict_types=1);

namespace Beziworld\Ml\Events;

use Beziworld\Ml\Data\Prediction;

/**
 * Dispatched for a sampled share of predictions when ml.events.predictions is enabled.
 */
final readonly class PredictionMade
{
    /**
     * @param array<string, mixed> $features feature name => raw value read from the subject
     */
    public function __construct(
        public Prediction $prediction,
        public array $features,
    ) {}
}
