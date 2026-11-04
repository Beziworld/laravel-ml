<?php

declare(strict_types=1);

namespace Beziworld\Ml\Tests;

use Beziworld\Ml\MlServiceProvider;
use Beziworld\Ml\Tests\Fixtures\Models\OrderAnomalies;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        mt_srand(42);
        OrderAnomalies::$records = null;
    }

    protected function getPackageProviders($app): array
    {
        return [MlServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('ml.disk', 'local');
        $app['config']->set('ml.training.seed', 42);
        $app['config']->set('ml.validation.strategy', 'holdout');
    }

    protected function createCustomersTable(): void
    {
        Schema::create('customers', static function (Blueprint $table): void {
            $table->id();
            $table->string('plan');
            $table->integer('age');
            $table->float('monthly_spend');
            $table->integer('logins');
            $table->boolean('churned');
            $table->dateTime('signed_up_at')->nullable();
            $table->text('churn_prediction')->nullable();
        });
    }
}
