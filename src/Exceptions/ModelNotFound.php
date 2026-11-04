<?php

declare(strict_types=1);

namespace Beziworld\Ml\Exceptions;

/**
 * Raised when a stored model or one of its versions does not exist.
 */
final class ModelNotFound extends MlException
{
    public static function model(string $model): self
    {
        return new self(sprintf('Model "%s" has no active version. Train it first, e.g. php artisan ml:train.', $model));
    }

    public static function version(string $model, string $version): self
    {
        return new self(sprintf('Model "%s" has no version "%s".', $model, $version));
    }
}
