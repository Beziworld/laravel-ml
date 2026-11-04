<?php

declare(strict_types=1);

namespace Beziworld\Ml\Exceptions;

/**
 * Raised when records cannot be turned into a dataset.
 */
final class InvalidDataset extends MlException
{
    public static function because(string $reason): self
    {
        return new self('Invalid dataset: ' . $reason);
    }
}
