<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle;

use RoundlyConsulting\Lifecycle\Accessors\DefinitionsAccessor;
use RoundlyConsulting\Lifecycle\Actions\PruneAction;
use RoundlyConsulting\Lifecycle\Commands\AdoptCommand;
use RoundlyConsulting\Lifecycle\Commands\GraphCommand;
use RoundlyConsulting\Lifecycle\Commands\MakeLifecycleCommand;
use RoundlyConsulting\Lifecycle\Commands\PruneCommand;
use RoundlyConsulting\Lifecycle\Commands\ShowCommand;
use RoundlyConsulting\Lifecycle\Commands\SweepCommand;
use RoundlyConsulting\Lifecycle\Commands\ValidateCommand;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Graph\GraphExporter;
use RoundlyConsulting\Lifecycle\Support\Isolation;
use RoundlyConsulting\Lifecycle\Support\ScheduleModel;
use RoundlyConsulting\Lifecycle\Support\StateModel;
use RoundlyConsulting\Lifecycle\Support\SweepSchedule;
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
            ->hasCommands([
                SweepCommand::class,
                GraphCommand::class,
                ValidateCommand::class,
                ShowCommand::class,
                AdoptCommand::class,
                PruneCommand::class,
                MakeLifecycleCommand::class,
            ])
            // `php artisan vendor:publish --tag=lifecycle-stubs`: make:lifecycle then uses the
            // host's copies from base_path('stubs').
            ->publishesStubs(__DIR__.'/Commands/stubs/lifecycle.stub', $this->app->basePath('stubs/lifecycle.stub'), 'lifecycle-stubs')
            ->publishesStubs(__DIR__.'/Commands/stubs/lifecycle.enum.stub', $this->app->basePath('stubs/lifecycle.enum.stub'), 'lifecycle-stubs')
            // Presence and flags only — never a payload or a secret.
            ->contributesToAbout(static fn (): array => [
                'Graph format' => GraphExporter::defaultFormat()->value,
                'Key type' => KeyType::fromConfig('lifecycle.key_type')->value,
                'Actor key type' => KeyType::fromConfig('lifecycle.actor_key_type')->value,
                'Strict state writes' => Config::using(InvalidLifecycleConfigurationException::class)->boolean('lifecycle.strict_writes', true) ? 'ON' : 'OFF',
                'Actor from auth' => Config::using(InvalidLifecycleConfigurationException::class)->boolean('lifecycle.actor.from_auth', true) ? 'ON' : 'OFF',
                'Registered subjects' => (string) count(app(DefinitionsAccessor::class)->subjects()),
                'State model' => class_basename(StateModel::class()),
                'History model' => class_basename(TransitionModel::class()),
                'Schedule model' => class_basename(ScheduleModel::class()),
                'Schedule batch size' => (string) Config::using(InvalidLifecycleConfigurationException::class)
                    ->integer('lifecycle.schedules.batch_size', 500, 1, 10000),
                'Queued sweeps' => Config::using(InvalidLifecycleConfigurationException::class)->boolean('lifecycle.schedules.queue.enabled') ? 'ON' : 'OFF',
                'MySQL quota isolation' => Config::using(InvalidLifecycleConfigurationException::class)->boolean('lifecycle.transactions.mysql_read_committed', true) ? 'READ COMMITTED' : 'locking reads',
                'Sweep scheduled' => match (SweepSchedule::isScheduled()) {
                    true => 'yes',
                    false => 'no',
                    null => 'unknown',
                },
                'History pruning' => ($days = PruneAction::days('lifecycle.history.prune_after_days')) === null ? 'OFF' : $days.' days',
            ]);
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(LifecycleManager::class);
        // Holds only immutable compiled definitions, so it may outlive a request (Octane).
        $this->app->singleton(DefinitionRegistry::class);
        // allowDirectWrites(), the engine's write marker and the READ COMMITTED marker live
        // per request / job.
        $this->app->scoped(WriteGuard::class);
        $this->app->scoped(Isolation::class);
    }

    public function boot(): void
    {
        parent::boot();

        // morphKey() must exist before the host runs the published migrations.
        $this->registerBlueprintMacros();
    }
}
