<?php

declare(strict_types=1);

namespace Beziworld\Ml\Console;

use Beziworld\Ml\MlManager;

/**
 * Deletes old versions, keeping the newest ones and always the active one.
 */
final class PruneCommand extends MlCommand
{
    protected $signature = 'ml:prune
        {model? : Model class, alias or name; all models when omitted}
        {--keep= : Number of newest versions to keep, ml.keep_versions by default}';

    protected $description = 'Delete old model versions';

    public function handle(MlManager $ml): int
    {
        $model = $this->input->getArgument('model');
        $keep = $this->input->getOption('keep');
        $keep = is_numeric($keep) ? (int) $keep : null;
        $models = is_string($model) ? [$model] : $ml->repository()->models();
        $total = 0;

        foreach ($models as $name) {
            $deleted = $ml->prune($name, $keep);
            $total += count($deleted);

            if ($deleted !== []) {
                $this->line(sprintf('%s: deleted %s', $name, implode(', ', $deleted)));
            }
        }

        $this->components->info(sprintf('Pruned %d version(s).', $total));

        return self::SUCCESS;
    }
}
