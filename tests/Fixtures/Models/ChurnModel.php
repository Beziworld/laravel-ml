<?php

declare(strict_types=1);

namespace Beziworld\Ml\Tests\Fixtures\Models;

use Beziworld\Ml\Models\ClassificationModel;
use Beziworld\Ml\Tests\Fixtures\Customer;
use Illuminate\Database\Eloquent\Builder;
use Rubix\ML\Classifiers\ClassificationTree;
use Rubix\ML\Estimator;
use Rubix\ML\Learner;

final class ChurnModel extends ClassificationModel
{
    /**
     * @return array<array-key, mixed>
     */
    public function features(): array
    {
        return ['plan' => 'categorical', 'age' => 'numeric', 'monthly_spend' => 'numeric', 'logins' => 'numeric', 'signed_up_at' => 'date'];
    }

    public function label(): string
    {
        return 'churned';
    }

    /**
     * @return Builder<Customer>
     */
    public function query(): Builder
    {
        return Customer::query();
    }

    public function estimator(): Learner&Estimator
    {
        return new ClassificationTree(6);
    }
}
