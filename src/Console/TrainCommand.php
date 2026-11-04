<?php

declare(strict_types=1);

namespace Beziworld\Ml\Console;

use Beziworld\Ml\MlManager;
use Beziworld\Ml\Training\Validation;

/**
 * Trains a model synchronously or on the queue.
 */
final class TrainCommand extends MlCommand
{
    protected $signature = 'ml:train
        {model : Model class, alias from ml.models or model name}
        {--queue : Dispatch a TrainModel job instead of training now}
        {--folds= : Use k-fold cross validation with this many folds}
        {--holdout= : Use holdout validation with this test ratio, e.g. 0.2}
        {--no-validation : Skip cross validation}
        {--no-promote : Keep the current active version}';

    protected $description = 'Train a machine learning model and store a new version';

    public function handle(MlManager $ml): int
    {
        $model = $this->text('model');
        $validation = $this->validation();
        $promote = $this->input->getOption('no-promote') === true ? false : null;

        if ($this->input->getOption('queue') === true) {
            $job = $ml->queue($model, $validation, $promote);
            $this->components->info(sprintf('Training of %s queued on %s.', $model, $job->queue ?? 'the default queue'));

            return self::SUCCESS;
        }

        $report = $ml->train($model, validation: $validation, promote: $promote);

        $this->components->info(sprintf(
            '%s %s trained on %d samples in %.1f s, validation: %s%s.',
            $report->model,
            $report->version,
            $report->samples,
            $report->durationMs / 1000,
            $report->validation,
            $report->promoted ? ', promoted to active' : '',
        ));

        $this->table(['Metric', 'Value'], array_map(
            static fn(string $metric, float $value): array => [$metric, number_format($value, 4)],
            array_keys($report->metrics),
            array_values($report->metrics),
        ));

        return self::SUCCESS;
    }

    private function validation(): ?Validation
    {
        if ($this->input->getOption('no-validation') === true) {
            return Validation::none();
        }

        $folds = $this->input->getOption('folds');

        if (is_numeric($folds)) {
            return Validation::kFold((int) $folds);
        }

        $holdout = $this->input->getOption('holdout');

        return is_numeric($holdout) ? Validation::holdout((float) $holdout) : null;
    }
}
