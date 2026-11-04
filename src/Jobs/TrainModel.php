<?php

declare(strict_types=1);

namespace Beziworld\Ml\Jobs;

use Beziworld\Ml\Data\TrainingReport;
use Beziworld\Ml\MlConfig;
use Beziworld\Ml\MlManager;
use Beziworld\Ml\Training\Validation;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Trains a model definition on a queue worker. The queue, the connection and the timeout
 * come from ml.queue and ml.training.time_limit.
 */
final class TrainModel implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** Training is not idempotent (every run stores a new version), so it is never retried. */
    public int $tries = 1;

    public ?int $timeout = null;

    /**
     * @param class-string $model
     */
    public function __construct(
        public readonly string $model,
        public readonly ?string $strategy = null,
        public readonly ?float $ratio = null,
        public readonly ?int $folds = null,
        public readonly ?bool $promote = null,
    ) {
        $config = Container::getInstance()->make(MlConfig::class);

        $this->timeout = $config->timeLimit;
        $this->onQueue($config->queueName);
        $this->onConnection($config->queueConnection);
    }

    public function handle(MlManager $ml): TrainingReport
    {
        return $ml->train($this->model, validation: $this->validation(), promote: $this->promote);
    }

    public function validation(): ?Validation
    {
        if ($this->strategy === null) {
            return null;
        }

        return new Validation($this->strategy, $this->ratio ?? 0.2, $this->folds ?? 5);
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['ml', 'ml:' . $this->model];
    }
}
