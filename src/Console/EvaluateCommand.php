<?php

declare(strict_types=1);

namespace Beziworld\Ml\Console;

use Beziworld\Ml\MlManager;

/**
 * Scores a stored version on the current training data of its definition.
 */
final class EvaluateCommand extends MlCommand
{
    protected $signature = 'ml:evaluate
        {model : Model class or alias}
        {model_version? : Version to evaluate, the active one by default}';

    protected $description = 'Evaluate a stored model version on current data';

    public function handle(MlManager $ml): int
    {
        $version = $this->input->getArgument('model_version');
        $report = $ml->evaluate($this->text('model'), is_string($version) ? $version : null);

        $this->components->info(sprintf('%s %s evaluated on %d samples.', $report->model, $report->version, $report->samples));

        $this->table(['Metric', 'Value'], array_map(
            static fn(string $metric, float $value): array => [$metric, number_format($value, 4)],
            array_keys($report->metrics),
            array_values($report->metrics),
        ));

        if ($report->confusionMatrix !== null) {
            $classes = array_map('strval', array_keys($report->confusionMatrix));
            $rows = [];

            foreach ($report->confusionMatrix as $actual => $predicted) {
                $rows[] = [$actual, ...array_map('strval', array_values($predicted))];
            }

            $this->line('Confusion matrix (rows: actual, columns: predicted)');
            $this->table(['', ...$classes], $rows);
        }

        return self::SUCCESS;
    }
}
