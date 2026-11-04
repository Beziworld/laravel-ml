<?php

declare(strict_types=1);

namespace Beziworld\Ml\Console;

use Beziworld\Ml\MlManager;

/**
 * Re-activates the version preceding the active one.
 */
final class RollbackCommand extends MlCommand
{
    protected $signature = 'ml:rollback {model : Model class, alias or name}';

    protected $description = 'Activate the previous version of a model';

    public function handle(MlManager $ml): int
    {
        $metadata = $ml->rollback($this->text('model'));

        $this->components->info(sprintf('%s rolled back to %s.', $metadata->name, $metadata->version));

        return self::SUCCESS;
    }
}
