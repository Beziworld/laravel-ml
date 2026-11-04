<?php

declare(strict_types=1);

namespace Beziworld\Ml\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Shared helpers of the package commands.
 */
abstract class MlCommand extends Command
{
    /**
     * Read a required string argument.
     */
    protected function text(string $argument): string
    {
        $value = $this->input->getArgument($argument);

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('The "%s" argument is required.', $argument));
        }

        return $value;
    }
}
