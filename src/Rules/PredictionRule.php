<?php

declare(strict_types=1);

namespace Beziworld\Ml\Rules;

use Beziworld\Ml\Data\Prediction;
use Beziworld\Ml\MlManager;
use Beziworld\Ml\Models\MachineLearningModel;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Base class of validation rules that run a model on the validated input.
 *
 * The subject passed to the model is:
 * - the value itself when it is an array (e.g. a nested "order" payload),
 * - ['feature' => value] when the value is scalar and the model has a single feature
 *   (e.g. a message for a spam filter),
 * - otherwise the whole validated data set.
 */
abstract class PredictionRule implements DataAwareRule, ValidationRule
{
    /** @var array<array-key, mixed> */
    protected array $data = [];

    /**
     * @param MachineLearningModel|class-string<MachineLearningModel>|string $model
     */
    public function __construct(protected MachineLearningModel|string $model) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    abstract protected function passes(Prediction $prediction): bool;

    abstract protected function message(): string;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $ml = Container::getInstance()->make(MlManager::class);
        $definition = $ml->definition($this->model);

        if (is_array($value)) {
            $subject = $value;
        } elseif (count($definition->schema()->features) === 1) {
            $subject = [$definition->schema()->names()[0] => $value];
        } else {
            $subject = $this->data;
        }

        if (! $this->passes($ml->predict($definition, $subject))) {
            $fail($this->message());
        }
    }
}
