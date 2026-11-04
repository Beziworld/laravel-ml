<?php

declare(strict_types=1);

namespace Beziworld\Ml\Models;

use Beziworld\Ml\Exceptions\InvalidModelDefinition;
use Beziworld\Ml\MlConfig;
use Beziworld\Ml\Persistence\ModelRepository;
use Illuminate\Contracts\Container\Container;

/**
 * Turns a class name, an alias from ml.models, a stored model name or an instance into a definition.
 */
final readonly class ModelResolver
{
    public function __construct(
        private Container $container,
        private MlConfig $config,
        private ModelRepository $repository,
    ) {}

    /**
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     */
    public function resolve(MachineLearningModel|string $model): MachineLearningModel
    {
        if ($model instanceof MachineLearningModel) {
            return $model;
        }

        $class = $this->config->models[$model] ?? $model;

        if (class_exists($class)) {
            if (! is_subclass_of($class, MachineLearningModel::class)) {
                throw InvalidModelDefinition::because($class, 'the class must extend ' . MachineLearningModel::class . '.');
            }

            $definition = $this->container->make($class);

            if (! $definition instanceof MachineLearningModel) {
                throw InvalidModelDefinition::unknown($model);
            }

            return $definition;
        }

        foreach ($this->config->models as $aliasClass) {
            if ($this->nameOf($aliasClass) === $model) {
                return $this->resolve($aliasClass);
            }
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $model) === 1 && $this->repository->exists($model)) {
            $versions = $this->repository->versions($model);
            $active = array_values(array_filter($versions, static fn($metadata): bool => $metadata->active));

            return new StoredModel($active[0] ?? $versions[count($versions) - 1]);
        }

        throw InvalidModelDefinition::unknown($model);
    }

    /**
     * @param class-string $class
     */
    private function nameOf(string $class): ?string
    {
        $definition = $this->container->make($class);

        return $definition instanceof MachineLearningModel ? $definition->name() : null;
    }
}
