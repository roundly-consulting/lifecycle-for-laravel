<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle;

use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Graph\GraphExporter;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;

final class LifecycleServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        // The Lifecycles facade's global alias is declared in composer.json
        // (extra.laravel.aliases): package discovery registers it without booting any code.
        $package
            ->name('lifecycle')
            ->hasConfigFile()
            ->hasTranslations()
            // Presence and flags only — never a payload or a secret.
            ->contributesToAbout(static fn (): array => [
                'Graph format' => GraphExporter::defaultFormat()->value,
            ]);
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(LifecycleManager::class);
        // Holds only immutable compiled definitions, so it may outlive a request (Octane).
        $this->app->singleton(DefinitionRegistry::class);
    }
}
