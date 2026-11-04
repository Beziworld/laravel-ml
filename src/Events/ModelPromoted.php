<?php

declare(strict_types=1);

namespace Beziworld\Ml\Events;

/**
 * Dispatched when a model version becomes active (promotion or rollback).
 */
final readonly class ModelPromoted
{
    public function __construct(
        public string $model,
        public string $version,
        public ?string $previous,
    ) {}
}
