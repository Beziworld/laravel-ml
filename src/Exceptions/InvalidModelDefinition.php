<?php

declare(strict_types=1);

namespace Beziworld\Ml\Exceptions;

/**
 * Raised when a model definition cannot be resolved or is inconsistent.
 */
final class InvalidModelDefinition extends MlException
{
    public static function unknown(string $model): self
    {
        return new self(sprintf('Unknown model "%s". Pass a MachineLearningModel class name, an alias from ml.models or the name of a stored model.', $model));
    }

    public static function because(string $model, string $reason): self
    {
        return new self(sprintf('Model "%s" is not valid: %s', $model, $reason));
    }
}
