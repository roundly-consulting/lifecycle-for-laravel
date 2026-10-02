<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Lifecycle\LifecycleServiceProvider;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\InlineLifecycle;
use RoundlyConsulting\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    /**
     * Every test runs with a non-UTC application timezone so a local-time leak into stored
     * or bound values shows up.
     */
    public const string TIMEZONE = 'Europe/Bratislava';

    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [LifecycleServiceProvider::class];
    }

    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        return [LifecycleServiceProvider::class, __DIR__.'/Fixtures/migrations'];
    }

    protected function setUp(): void
    {
        InlineLifecycle::$define = null;

        parent::setUp();
    }

    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return ['app.timezone' => self::TIMEZONE];
    }

    /**
     * Laravel already called date_default_timezone_set() from `app.timezone` before this
     * runs, so setting the config key alone leaves PHP — and every Date::now()/Date::parse()
     * — on UTC, and each "local time vs UTC" test would pass vacuously.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        date_default_timezone_set(self::TIMEZONE);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        date_default_timezone_set('UTC');
    }
}
