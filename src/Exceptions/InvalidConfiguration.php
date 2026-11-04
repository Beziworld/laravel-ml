<?php

declare(strict_types=1);

namespace Beziworld\Ml\Exceptions;

/**
 * Raised when the package configuration is incomplete or invalid.
 */
final class InvalidConfiguration extends MlException
{
    public static function missing(string $key, string $env): self
    {
        return new self(sprintf('The ml.%s configuration value is missing. Set %s in the environment.', $key, $env));
    }

    public static function invalid(string $key, string $env, string $reason): self
    {
        return new self(sprintf('The ml.%s configuration value is invalid (%s). Check %s in the environment.', $key, $reason, $env));
    }
}
