<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle;

use RoundlyConsulting\Lifecycle\Accessors\DefinitionsAccessor;
use RoundlyConsulting\Lifecycle\Commands\SweepCommand;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Graph\GraphExporter;
use RoundlyConsulting\Lifecycle\Support\ScheduleModel;
use RoundlyConsulting\Lifecycle\Support\StateModel;
use RoundlyConsulting\Lifecycle\Support\TransitionModel;
use RoundlyConsulting\Lifecycle\Support\WriteGuard;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\PackageToolkit\Support\Config;

final class LifecycleServiceProvider extends PackageServiceProvider
{
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        // The Lifecycles facade's global alias is declared in composer.json
        // (extra.laravel.aliases): package discovery registers it without booting any code.
        $package
            ->name('lifecycle')
            ->hasConfigFile()
            // Publish-only, timestamped on publish, in directory order.
            ->hasMigrations()
            ->hasTranslations()
            ->hasCommands([SweepCommand::class])
            // Presence and flags only — never a payload or a secret.
            ->contributesToAbout(static fn (): array => [
                'Graph format' => GraphExporter::defaultFormat()->value,
                'Key type' => KeyType::fromConfig('lifecycle.key_type')->value,
                'Actor key type' => KeyType::fromConfig('lifecycle.actor_key_type')->value,
                'Strict state writes' => Config::boolean('lifecycle.strict_writes', true) ? 'ON' : 'OFF',
                'Actor from auth' => Config::boolean('lifecycle.actor.from_auth', true) ? 'ON' : 'OFF',
                'Registered definitions' => (string) count(app(DefinitionsAccessor::class)->registered()),
                'State model' => class_basename(StateModel::class()),
                'History model' => class_basename(TransitionModel::class()),
                'Schedule model' => class_basename(ScheduleModel::class()),
                'Schedule batch size' => (string) Config::using(InvalidLifecycleConfigurationException::class)
                    ->intBetween('lifecycle.schedules.batch_size', 1, 10000, 500),
                'Queued sweeps' => Config::boolean('lifecycle.schedules.queue.enabled') ? 'ON' : 'OFF',
            ]);
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(LifecycleManager::class);
        // Holds only immutable compiled definitions, so it may outlive a request (Octane).
        $this->app->singleton(DefinitionRegistry::class);
        // allowDirectWrites() and the engine's write marker live per request / job.
        $this->app->scoped(WriteGuard::class);
    }

    public function boot(): void
    {
        parent::boot();

        // morphKey() must exist before the host runs the published migrations.
        $this->registerBlueprintMacros();
    }
}
