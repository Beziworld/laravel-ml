<?php

declare(strict_types=1);

namespace Beziworld\Ml\Facades;

use Beziworld\Ml\MlManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Beziworld\Ml\Dataset\DatasetBuilder dataset()
 * @method static \Beziworld\Ml\Models\ModelBuilder model(string $name)
 * @method static \Beziworld\Ml\Models\MachineLearningModel definition(\Beziworld\Ml\Models\MachineLearningModel|string $model)
 * @method static \Beziworld\Ml\Data\TrainingReport train(\Beziworld\Ml\Models\MachineLearningModel|string $model, \Beziworld\Ml\Dataset\Dataset|null $dataset = null, \Beziworld\Ml\Training\Validation|null $validation = null, bool|null $promote = null)
 * @method static \Beziworld\Ml\Jobs\TrainModel queue(string $model, \Beziworld\Ml\Training\Validation|null $validation = null, bool|null $promote = null)
 * @method static \Beziworld\Ml\Data\EvaluationReport evaluate(\Beziworld\Ml\Models\MachineLearningModel|string $model, string|null $version = null, \Beziworld\Ml\Dataset\Dataset|null $dataset = null)
 * @method static \Beziworld\Ml\Data\Prediction predict(\Beziworld\Ml\Models\MachineLearningModel|string $model, mixed $subject, string|null $version = null)
 * @method static \Illuminate\Support\Collection<int, \Beziworld\Ml\Data\Prediction> predictMany(\Beziworld\Ml\Models\MachineLearningModel|string $model, iterable<mixed> $subjects, string|null $version = null)
 * @method static array<string, float> probabilities(\Beziworld\Ml\Models\MachineLearningModel|string $model, mixed $subject, string|null $version = null)
 * @method static \Illuminate\Support\Collection<int, \Beziworld\Ml\Data\Recommendation> recommend(\Beziworld\Ml\Models\MachineLearningModel|string $model, mixed $subject, int|null $limit = null, string|null $version = null)
 * @method static \Beziworld\Ml\Data\DriftReport drift(\Beziworld\Ml\Models\MachineLearningModel|string $model, iterable<mixed>|\Beziworld\Ml\Dataset\Dataset $records, string|null $version = null)
 * @method static list<\Beziworld\Ml\Data\ModelMetadata> versions(\Beziworld\Ml\Models\MachineLearningModel|string $model)
 * @method static \Beziworld\Ml\Data\ModelMetadata promote(\Beziworld\Ml\Models\MachineLearningModel|string $model, string $version)
 * @method static \Beziworld\Ml\Data\ModelMetadata rollback(\Beziworld\Ml\Models\MachineLearningModel|string $model)
 * @method static list<string> prune(\Beziworld\Ml\Models\MachineLearningModel|string $model, int|null $keep = null)
 * @method static \Beziworld\Ml\Association\Apriori apriori(float $support = 0.1, float $confidence = 0.5, int $maxLength = 0)
 * @method static \Beziworld\Ml\Persistence\ModelRepository repository()
 * @method static \Beziworld\Ml\MlConfig config()
 *
 * @see MlManager
 */
final class Ml extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MlManager::class;
    }
}
