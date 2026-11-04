<?php

declare(strict_types=1);

namespace Beziworld\Ml\Console;

use Beziworld\Ml\Data\ModelMetadata;
use Beziworld\Ml\MlManager;

/**
 * Lists stored models, or the versions of one model with their metrics.
 */
final class ModelsCommand extends MlCommand
{
    protected $signature = 'ml:models {model? : Show the versions of this model}';

    protected $description = 'List stored machine learning models and versions';

    public function handle(MlManager $ml): int
    {
        $model = $this->input->getArgument('model');
        $repository = $ml->repository();

        if (! is_string($model)) {
            $rows = [];

            foreach ($repository->models() as $name) {
                $versions = $repository->versions($name);
                $latest = $versions === [] ? null : $versions[count($versions) - 1];

                $rows[] = [
                    $name,
                    $repository->activeVersion($name) ?? '-',
                    (string) count($versions),
                    $latest === null ? '-' : $latest->task,
                    $latest === null ? '-' : $latest->trainedAt->toDateTimeString(),
                ];
            }

            if ($rows === []) {
                $this->components->info('No models stored yet.');

                return self::SUCCESS;
            }

            $this->table(['Model', 'Active', 'Versions', 'Task', 'Last trained'], $rows);

            return self::SUCCESS;
        }

        $versions = $ml->versions($model);

        if ($versions === []) {
            $this->components->warn(sprintf('No versions of %s are stored.', $model));

            return self::SUCCESS;
        }

        $this->table(['Version', 'Active', 'Trained at', 'Samples', 'Validation', 'Metrics'], array_map(
            static fn(ModelMetadata $metadata): array => [
                $metadata->version,
                $metadata->active ? 'yes' : '',
                $metadata->trainedAt->toDateTimeString(),
                (string) $metadata->samples,
                $metadata->validation,
                implode(', ', array_map(
                    static fn(string $metric, float $value): string => sprintf('%s=%s', $metric, number_format($value, 4)),
                    array_keys($metadata->metrics),
                    array_values($metadata->metrics),
                )),
            ],
            $versions,
        ));

        return self::SUCCESS;
    }
}
