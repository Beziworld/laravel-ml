<?php

declare(strict_types=1);

namespace Beziworld\Ml\Tests\Fixtures;

use Beziworld\Ml\Casts\AsPrediction;
use Beziworld\Ml\Concerns\HasPredictions;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $plan
 * @property int $age
 * @property float $monthly_spend
 * @property int $logins
 * @property bool $churned
 * @property \Beziworld\Ml\Data\Prediction|null $churn_prediction
 */
final class Customer extends Model
{
    use HasPredictions;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'churned' => 'boolean',
            'monthly_spend' => 'float',
            'signed_up_at' => 'datetime',
            'churn_prediction' => AsPrediction::class,
        ];
    }
}
