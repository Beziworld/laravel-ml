<?php

declare(strict_types=1);

namespace Beziworld\Ml\Console;

use Beziworld\Ml\MlManager;

/**
 * Makes a stored version the active one.
 */
final class PromoteCommand extends MlCommand
{
    protected $signature = 'ml:promote
        {model : Model class, alias or name}
        {model_version : Version to activate, e.g. v3}';

    protected $description = 'Promote a model version to active';

    public function handle(MlManager $ml): int
    {
        $metadata = $ml->promote($this->text('model'), $this->text('model_version'));

        $this->components->info(sprintf('%s %s is now active.', $metadata->name, $metadata->version));

        return self::SUCCESS;
    }
}
