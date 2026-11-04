<?php

declare(strict_types=1);

namespace Beziworld\Ml\Casts;

use Beziworld\Ml\Data\Prediction;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a Prediction as JSON in a text or JSON column and reads it back as a DTO.
 *
 *     protected function casts(): array
 *     {
 *         return ['churn_prediction' => AsPrediction::class];
 *     }
 *
 *     $user->churn_prediction = $user->predict(ChurnModel::class);
 *
 * @implements CastsAttributes<Prediction, Prediction|array<string, mixed>>
 */
final class AsPrediction implements CastsAttributes
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Prediction
    {
        if ($value === null || $value === '') {
            return null;
        }

        $data = is_string($value) ? json_decode($value, true) : $value;

        return is_array($data) ? Prediction::fromArray($data) : null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Prediction) {
            $value = $value->toArray();
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }
}
