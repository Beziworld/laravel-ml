<?php

declare(strict_types=1);

namespace Beziworld\Ml;

use Beziworld\Ml\Console\DriftCommand;
use Beziworld\Ml\Console\EvaluateCommand;
use Beziworld\Ml\Console\ModelsCommand;
use Beziworld\Ml\Console\PromoteCommand;
use Beziworld\Ml\Console\PruneCommand;
use Beziworld\Ml\Console\RollbackCommand;
use Beziworld\Ml\Console\TrainCommand;
use Beziworld\Ml\Drift\DriftDetector;
use Beziworld\Ml\Models\ModelResolver;
use Beziworld\Ml\Persistence\ModelRepository;
use Beziworld\Ml\Persistence\ModelSerializer;
use Beziworld\Ml\Prediction\Predictor;
use Beziworld\Ml\Preprocessing\TransformerFactory;
use Beziworld\Ml\Training\Trainer;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the configuration, the training and prediction services, the facade root and the commands.
 */
final class MlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ml.php', 'ml');

        $this->app->singleton(MlConfig::class, static function (Application $app): MlConfig {
            $config = $app->make(Config::class);
            /** @var array<string, mixed> $ml */
            $ml = $config->get('ml', []);
            $appKey = $config->get('app.key');

            return MlConfig::fromArray($ml, is_string($appKey) ? $appKey : null);
        });

        $this->app->singleton(TransformerFactory::class, static fn(Application $app): TransformerFactory => new TransformerFactory($app->make(MlConfig::class)));
        $this->app->singleton(ModelSerializer::class, static fn(Application $app): ModelSerializer => new ModelSerializer($app->make(MlConfig::class)));

        $this->app->singleton(ModelRepository::class, static fn(Application $app): ModelRepository => new ModelRepository(
            $app->make(FilesystemFactory::class),
            $app->make(MlConfig::class),
            $app->make(ModelSerializer::class),
        ));

        $this->app->singleton(ModelResolver::class, static fn(Application $app): ModelResolver => new ModelResolver(
            $app,
            $app->make(MlConfig::class),
            $app->make(ModelRepository::class),
        ));

        $this->app->singleton(Trainer::class, static fn(Application $app): Trainer => new Trainer(
            $app->make(MlConfig::class),
            $app->make(TransformerFactory::class),
            $app->make(ModelRepository::class),
            $app->make(Dispatcher::class),
        ));

        $this->app->singleton(Predictor::class, static fn(Application $app): Predictor => new Predictor(
            $app->make(MlConfig::class),
            $app->make(ModelRepository::class),
            $app->make(Dispatcher::class),
        ));

        $this->app->singleton(DriftDetector::class, static fn(Application $app): DriftDetector => new DriftDetector($app->make(MlConfig::class)));

        $this->app->singleton(MlManager::class, static fn(Application $app): MlManager => new MlManager(
            $app->make(MlConfig::class),
            $app->make(ModelResolver::class),
            $app->make(ModelRepository::class),
            $app->make(Trainer::class),
            $app->make(Predictor::class),
            $app->make(DriftDetector::class),
            $app->make(TransformerFactory::class),
            $app->make(Dispatcher::class),
            $app->make(BusDispatcher::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                TrainCommand::class,
                EvaluateCommand::class,
                ModelsCommand::class,
                PromoteCommand::class,
                RollbackCommand::class,
                PruneCommand::class,
                DriftCommand::class,
            ]);

            $this->publishes([__DIR__ . '/../config/ml.php' => $this->app->configPath('ml.php')], 'ml-config');
        }
    }
}
