<?php

declare(strict_types=1);

namespace Beziworld\Ml\Models;

use Beziworld\Ml\Dataset\Dataset;
use Beziworld\Ml\Dataset\DatasetBuilder;
use Beziworld\Ml\Dataset\FeatureSchema;
use Beziworld\Ml\Enums\Task;
use Beziworld\Ml\Exceptions\InvalidModelDefinition;
use Beziworld\Ml\Preprocessing\Preprocessing;
use Beziworld\Ml\Training\Validation;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use Rubix\ML\Estimator;
use Rubix\ML\Learner;
use Rubix\ML\Transformers\Transformer;

/**
 * Definition of a machine learning model: what it predicts, from which features and with which estimator.
 *
 *     final class ChurnModel extends ClassificationModel
 *     {
 *         public function features(): array
 *         {
 *             return ['age' => 'numeric', 'plan' => 'categorical', 'last_login_at' => 'date'];
 *         }
 *
 *         public function label(): string
 *         {
 *             return 'churned';
 *         }
 *
 *         public function query(): Builder
 *         {
 *             return User::query()->whereNotNull('churned');
 *         }
 *     }
 *
 * Definitions must be stateless: estimator() and transformers() are called once per
 * cross validation fold and must return new instances on every call.
 */
abstract class MachineLearningModel
{
    abstract public function task(): Task;

    /**
     * Feature definition accepted by FeatureSchema::from().
     *
     * @return array<array-key, mixed>
     */
    abstract public function features(): array;

    /**
     * A new, untrained Rubix estimator.
     */
    public function estimator(): Learner&Estimator
    {
        return Estimators::defaultFor($this->task());
    }

    /**
     * Attribute (or closure) holding the target value; required by classifiers and regressors.
     *
     * @return string|(Closure(mixed): mixed)|null
     */
    public function label(): string|Closure|null
    {
        return null;
    }

    /**
     * Storage name of the model, used for directories and Artisan commands.
     */
    public function name(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    /**
     * Automatic preprocessing; null takes the dataset's preprocessing or the defaults.
     */
    public function preprocessing(): ?Preprocessing
    {
        return null;
    }

    /**
     * Custom Rubix transformers that replace the automatic preprocessing entirely.
     *
     * @return list<Transformer>|null
     */
    public function transformers(): ?array
    {
        return null;
    }

    /**
     * Query that provides the training records; alternative to overriding dataset().
     *
     * @return EloquentBuilder<covariant Model>|QueryBuilder|Relation<covariant Model, covariant Model, mixed>|null
     */
    public function query(): EloquentBuilder|QueryBuilder|Relation|null
    {
        return null;
    }

    /**
     * Attribute (or closure) identifying a record; used by recommenders to return item keys.
     *
     * @return string|(Closure(mixed): mixed)|null
     */
    public function key(): string|Closure|null
    {
        return null;
    }

    /**
     * Training data. The default implementation streams query() with features() and label().
     */
    public function dataset(): Dataset
    {
        $query = $this->query();

        if ($query === null) {
            throw InvalidModelDefinition::because($this->name(), 'override query() or dataset() to provide training data.');
        }

        return Container::getInstance()->make(DatasetBuilder::class)
            ->fromQuery($query, $this->features(), $this->label(), key: $this->key())
            ->get();
    }

    /**
     * Cross validation strategy; null takes the configured default.
     */
    public function validation(): ?Validation
    {
        return null;
    }

    /**
     * Random seed for reproducible training; null takes ml.training.seed.
     */
    public function seed(): ?int
    {
        return null;
    }

    final public function schema(): FeatureSchema
    {
        return FeatureSchema::from($this->features());
    }
}
