<?php

declare(strict_types=1);

namespace Beziworld\Ml\Exceptions;

/**
 * Raised when a stored model file fails verification and is refused before unserialisation.
 */
final class IntegrityCheckFailed extends MlException
{
    public static function because(string $model, string $version, string $reason): self
    {
        return new self(sprintf('Model "%s" %s was refused: %s', $model, $version, $reason));
    }
}
