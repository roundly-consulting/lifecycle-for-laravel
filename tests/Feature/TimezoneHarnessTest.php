<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use RoundlyConsulting\Lifecycle\Tests\TestCase;

/**
 * The UTC tests prove nothing if PHP still runs on UTC: pin that the harness really moved
 * both the application and PHP's default timezone.
 */
it('runs every test in a non-UTC timezone', function (): void {
    expect(date_default_timezone_get())->toBe(TestCase::TIMEZONE)
        ->and(config('app.timezone'))->toBe(TestCase::TIMEZONE)
        ->and(Date::now()->tzName)->toBe(TestCase::TIMEZONE);
});
