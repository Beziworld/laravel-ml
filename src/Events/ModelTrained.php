<?php

declare(strict_types=1);

namespace Beziworld\Ml\Events;

use Beziworld\Ml\Data\ModelMetadata;
use Beziworld\Ml\Data\TrainingReport;

/**
 * Dispatched after a model version has been trained and stored.
 */
final readonly class ModelTrained
{
    public function __construct(
        public TrainingReport $report,
        public ModelMetadata $metadata,
    ) {}
}
