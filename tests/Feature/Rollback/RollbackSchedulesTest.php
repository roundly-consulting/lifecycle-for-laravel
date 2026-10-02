<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Enums\ScheduleOutcome;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

it('cancels what the reverted row created and re-opens what it cancelled', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        $l->states(['a', 'b', 'z'])->initial('a')->terminal('z');
        $l->transition('ab')->from('a')->to('b');
        $l->transition('expire_a')->from('a')->to('z')->systemOnly();
        $l->transition('expire_b')->from('b')->to('z')->systemOnly();
        $l->transition('nudge')->from('b')->to('b')->allowSelf()->allowSystem();
        $l->state('a')->ttl('10 days')->expiresVia('expire_a');
        $l->state('b')->ttl('1 day')->expiresVia('expire_b');
    });
    $document = Document::factory()->create();
    $aExpiry = LifecycleSchedule::query()->sole();

    $document->transition('ab');
    $bExpiry = LifecycleSchedule::query()->where('for_state', 'b')->sole();
    $planned = Lifecycles::for($document)->asSystem()->schedule('nudge', CarbonImmutable::parse('2026-10-02 18:00:00', 'UTC'));

    Lifecycles::for($document)->rollback();

    expect($bExpiry->fresh()?->outcome)->toBe(ScheduleOutcome::Reverted)
        ->and(LifecycleSchedule::query()->find($planned->id)?->outcome)->toBe(ScheduleOutcome::StateLeft)
        ->and($aExpiry->fresh()?->status)->toBe(ScheduleStatus::Pending)
        ->and($aExpiry->fresh()?->pending_slot)->toBe('@expiry')
        ->and($aExpiry->fresh()?->outcome)->toBeNull()
        ->and($aExpiry->fresh()?->expires_at->toDateTimeString())->toBe('2026-10-12 10:00:00')
        ->and(Lifecycles::for($document)->expiresAt()?->toDateTimeString())->toBe('2026-10-12 10:00:00');
});

it('does not re-open a schedule whose slot was taken meanwhile', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        $l->states(['a', 'b', 'z'])->initial('a')->terminal('z');
        $l->transition('ab')->from('a')->to('b');
        $l->transition('expire_a')->from('a')->to('z')->systemOnly();
        $l->state('a')->ttl('10 days')->expiresVia('expire_a');
    });
    $document = Document::factory()->create();
    $document->transition('ab');
    LifecycleSchedule::factory()->expiry('expire_a', 'a')->create([
        'subject_type' => $document->getMorphClass(), 'subject_id' => $document->id, 'lifecycle' => 'status',
    ]);

    Lifecycles::for($document)->rollback();

    expect(LifecycleSchedule::query()->where('status', 'pending')->count())->toBe(1);
});
