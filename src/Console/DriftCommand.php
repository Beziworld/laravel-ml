<?php

declare(strict_types=1);

namespace Beziworld\Ml\Console;

use Beziworld\Ml\Data\FeatureDrift;
use Beziworld\Ml\MlManager;

/**
 * Compares the current data of a definition with the statistics of a stored version.
 * Exits with code 1 when drift is detected, which suits scheduled monitoring.
 */
final class DriftCommand extends MlCommand
{
    protected $signature = 'ml:drift
        {model : Model class or alias}
        {model_version? : Version to compare with, the active one by default}';

    protected $description = 'Check current data for drift against the training statistics';

    public function handle(MlManager $ml): int
    {
        $definition = $ml->definition($this->text('model'));
        $version = $this->input->getArgument('model_version');
        $report = $ml->drift($definition, $definition->dataset(), is_string($version) ? $version : null);

        $format = static fn(?float $value): string => $value === null ? '-' : number_format($value, 3);

        $this->table(['Feature', 'Type', 'Mean shift', 'Distribution shift', 'Missing shift', 'Drift'], array_map(
            static fn(FeatureDrift $feature): array => [
                $feature->name,
                $feature->type,
                $format($feature->meanShift),
                $format($feature->distributionShift),
                $format($feature->missingShift),
                $feature->drifted ? 'YES' : 'no',
            ],
            $report->features,
        ));

        if ($report->drifted()) {
            $this->components->warn(sprintf('Drift detected in: %s. Consider retraining %s.', implode(', ', $report->driftedFeatures()), $report->model));

            return self::FAILURE;
        }

        $this->components->info('No drift detected.');

        return self::SUCCESS;
    }
}
