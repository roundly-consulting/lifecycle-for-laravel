<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

/**
 * The application runs in Europe/Bratislava (see TestCase); everything the package stores
 * is UTC whatever the input zone.
 */
it('stores every package instant as a UTC string', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-07-01 08:00:00', 'America/New_York'));

    $listing = Listing::factory()->create();
    $listing->transition('publish');

    expect(DB::table('lifecycle_transitions')->where('kind', 'transition')->value('occurred_at'))->toStartWith('2026-07-01 12:00:00')
        ->and(DB::table('lifecycle_states')->value('entered_at'))->toStartWith('2026-07-01 12:00:00');

    Carbon::setTestNow();
});

it('keeps a 30-day TTL at 720 hours across the October DST change', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'Europe/Bratislava'));
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    $expires = DB::table('lifecycle_schedules')->value('expires_at');

    expect(CarbonImmutable::parse($expires, 'UTC')->diffInHours(CarbonImmutable::parse('2026-10-10 10:00:00', 'UTC'), true))->toEqual(720.0);
    Carbon::setTestNow();
});
