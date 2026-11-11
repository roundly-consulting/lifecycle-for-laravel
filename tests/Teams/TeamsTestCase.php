<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Teams;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Addresses\AddressesServiceProvider;
use RoundlyConsulting\Approvals\ApprovalsServiceProvider;
use RoundlyConsulting\Connections\ConnectionsServiceProvider;
use RoundlyConsulting\Contacts\ContactsServiceProvider;
use RoundlyConsulting\Crypto\CryptoServiceProvider;
use RoundlyConsulting\Lifecycle\LifecycleServiceProvider;
use RoundlyConsulting\Lifecycle\Tests\TestCase;
use RoundlyConsulting\Options\Facades\Options;
use RoundlyConsulting\Options\OptionsServiceProvider;
use RoundlyConsulting\Teams\TeamsServiceProvider;

/**
 * The multi-tenant app: teams-for-laravel installed and booted (with what it requires), and
 * `ScopedToTeam` subjects. Group `teams`: the no-teams CI leg removes the package and leaves it out.
 */
abstract class TeamsTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Options::flushCache();
    }

    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [
            CryptoServiceProvider::class,
            OptionsServiceProvider::class,
            ContactsServiceProvider::class,
            AddressesServiceProvider::class,
            ApprovalsServiceProvider::class,
            ConnectionsServiceProvider::class,
            TeamsServiceProvider::class,
            LifecycleServiceProvider::class,
        ];
    }

    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        return [
            OptionsServiceProvider::class,
            ContactsServiceProvider::class,
            AddressesServiceProvider::class,
            ConnectionsServiceProvider::class,
            ApprovalsServiceProvider::class,
            TeamsServiceProvider::class,
            LifecycleServiceProvider::class,
            __DIR__.'/../Fixtures/migrations',
            __DIR__.'/Fixtures/migrations',
        ];
    }

    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            ...parent::configBeforeBoot(),
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'options.cache.enabled' => false,
            'connections.cache.enabled' => false,
            'queue.default' => 'sync',
        ];
    }
}
