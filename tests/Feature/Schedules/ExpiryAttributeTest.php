<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

/**
 * An expiry taken from an attribute (`expiresAtAttribute`) follows that attribute: through a
 * handler, from a stale model, on a soft-deleted subject — and not after `neverExpire()`.
 */
beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

function attributeExpiry(?Closure $extend = null): void
{
    defineDocumentLifecycle(function (LifecycleBuilder $l) use ($extend): void {
        $l->states(['a', 'b', 'd', 'x'])->initial('a')->terminal('x');
        $l->state('b')->expiresAtAttribute('expires_at')->expiresVia('expire');
        $l->transition('go')->from('a')->to('b');
        $l->transition('park')->from('b')->to('d');
        $l->transition('back')->from('d')->to('b');
        $l->transition('expire')->from('*')->to('x')->allowSystem();
        $l->transition('extend')->from('b')->to('b')->allowSelf()->handledBy($extend ?? static function (): void {});
    });
}

function pendingExpiry(Document $document): ?LifecycleSchedule
{
    return LifecycleSchedule::query()->where('subject_id', $document->id)->where('pending_slot', '@expiry')->first();
}

it('follows the attribute a self-transition handler moves', function (): void {
    attributeExpiry(fn (TransitionContext $c) => $c->subject->setAttribute('expires_at', CarbonImmutable::parse('2027-10-02 10:00:00', 'UTC')));
    $document = Document::factory()->create(['expires_at' => CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC')]);
    $document->transition('go');
    $created = pendingExpiry($document)?->created_by_transition_id;

    $document->transition('extend');

    expect(Lifecycles::for($document)->expiresAt()?->equalTo($document->fresh()?->expires_at))->toBeTrue()
        ->and(Lifecycles::for($document)->expiresAt()?->year)->toBe(2027)
        ->and(pendingExpiry($document)?->created_by_transition_id)->toBe($created);

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-06 10:00:00', 'UTC'));
    Lifecycles::sweep();

    expect($document->fresh()?->status)->toBe('b');
});

it('keeps the pending row of a self-transition that leaves the attribute alone', function (): void {
    attributeExpiry();
    $document = Document::factory()->create(['expires_at' => CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC')]);
    $document->transition('go');
    $id = pendingExpiry($document)?->id;

    $document->transition('extend');

    expect(pendingExpiry($document)?->id)->toBe($id);
});

it('ignores an expiry attribute saved by a stale model whose state has moved on', function (): void {
    attributeExpiry();
    $document = Document::factory()->create(['expires_at' => CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC')]);
    $document->transition('go');
    Document::query()->findOrFail($document->id)->transition('park');

    $document->update(['expires_at' => CarbonImmutable::parse('2026-10-03 10:00:00', 'UTC')]);

    expect(pendingExpiry($document))->toBeNull();

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 10:00:00', 'UTC'));
    Lifecycles::sweep();

    expect($document->fresh()?->status)->toBe('d');
});

it('follows the attribute of a soft-deleted subject without resuming its expiry', function (): void {
    attributeExpiry();
    $document = Document::factory()->create(['expires_at' => CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC')]);
    $document->transition('go');
    $document->delete();

    $document->update(['expires_at' => CarbonImmutable::parse('2026-12-01 10:00:00', 'UTC')]);

    expect(pendingExpiry($document)?->status)->toBe(ScheduleStatus::Paused)
        ->and(pendingExpiry($document)?->expires_at?->equalTo($document->fresh()?->expires_at))->toBeTrue();

    $document->restore();

    expect(pendingExpiry($document)?->status)->toBe(ScheduleStatus::Pending)
        ->and(Lifecycles::for($document)->expiresAt()?->month)->toBe(12);
});

it('adopts an allowed state write on a soft-deleted subject, keeping its schedules paused', function (): void {
    attributeExpiry();
    $document = Document::factory()->create(['expires_at' => CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC')]);
    $document->delete();

    Lifecycles::allowDirectWrites(fn () => $document->update(['status' => 'b']));

    expect(LifecycleState::query()->where('subject_id', $document->id)->value('state'))->toBe('b')
        ->and(pendingExpiry($document)?->status)->toBe(ScheduleStatus::Paused);
});

it('keeps neverExpire() for the rest of the stay, whatever the attribute does', function (): void {
    attributeExpiry();
    $document = Document::factory()->create(['expires_at' => CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC')]);
    $document->transition('go');

    expect(Lifecycles::for($document)->neverExpire())->toBeTrue();

    $document->update(['expires_at' => CarbonImmutable::parse('2026-10-06 10:00:00', 'UTC')]);
    $document->transition('extend');

    expect(Lifecycles::for($document)->expiresAt())->toBeNull();

    // A new stay follows the attribute again.
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:01:00', 'UTC'));
    $document->transition('park');
    $document->transition('back');

    expect(Lifecycles::for($document)->expiresAt()?->day)->toBe(6);
});

it('keeps neverExpire() even when nothing was pending yet', function (): void {
    attributeExpiry();
    $document = Document::factory()->create(['expires_at' => null]);
    $document->transition('go');

    expect(Lifecycles::for($document)->neverExpire())->toBeFalse();

    $document->update(['expires_at' => CarbonImmutable::parse('2026-10-06 10:00:00', 'UTC')]);

    expect(Lifecycles::for($document)->expiresAt())->toBeNull();
});
