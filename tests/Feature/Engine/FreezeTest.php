<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Lifecycle\DataTransferObjects\FreezeRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\UnfreezeRequest;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Events\LifecycleFrozen;
use RoundlyConsulting\Lifecycle\Events\LifecycleUnfrozen;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectNotPersistedException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

it('freezes and unfreezes a lifecycle with events after commit', function (): void {
    Event::fake([LifecycleFrozen::class, LifecycleUnfrozen::class]);
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, finish: fn (TransitionBuilder $f) => $f->ignoresFreeze()));
    $document = Document::factory()->create();
    $user = User::factory()->create();

    expect(Lifecycles::for($document)->by($user)->because('Audit')->freeze())->toBeTrue()
        ->and(Lifecycles::for($document)->isFrozen())->toBeTrue()
        ->and(Lifecycles::for($document)->frozenUntil())->toBeNull()
        ->and(Lifecycles::for($document)->check('go')->codes())->toBe(['frozen'])
        ->and(fn () => $document->transition('go'))->toThrow(TransitionDeniedException::class, 'This record is frozen.');

    $record = LifecycleState::query()->sole();

    expect($record->frozen_reason)->toBe('Audit')
        ->and($record->frozen_by_id)->toEqual($user->id)
        ->and(Lifecycles::for($document)->unfreeze())->toBeTrue()
        ->and(Lifecycles::for($document)->unfreeze())->toBeFalse()
        ->and(Lifecycles::for($document)->isFrozen())->toBeFalse();

    Event::assertDispatched(LifecycleFrozen::class, fn (LifecycleFrozen $e): bool => $e->reason === 'Audit' && $e->actor?->is($user) === true);
    Event::assertDispatchedTimes(LifecycleUnfrozen::class, 1);
});

it('lets transitions that ignore the freeze through', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, finish: fn (TransitionBuilder $f) => $f->ignoresFreeze()));
    $document = Document::factory()->create();
    $document->transition('go');
    Lifecycles::for($document)->freeze();

    expect($document->transition('finish')->to)->toBe('c');
});

it('updates a freeze in place and fires only on a change', function (): void {
    Event::fake([LifecycleFrozen::class]);
    $listing = Listing::factory()->create();
    $until = CarbonImmutable::parse('2026-10-05 12:00:00', 'Europe/Bratislava');

    expect(Lifecycles::for($listing)->freeze($until))->toBeTrue()
        ->and(Lifecycles::for($listing)->freeze($until))->toBeFalse()
        ->and(Lifecycles::for($listing)->because('extended')->freeze($until))->toBeTrue()
        ->and(Lifecycles::for($listing)->frozenUntil()?->toDateTimeString())->toBe('2026-10-05 10:00:00')
        ->and(Lifecycles::for($listing)->because('extended')->freeze())->toBeTrue()
        ->and(Lifecycles::for($listing)->frozenUntil())->toBeNull();

    Event::assertDispatchedTimes(LifecycleFrozen::class, 3);
});

it('lets a freeze lapse at its end', function (): void {
    Event::fake([LifecycleUnfrozen::class]);
    $listing = Listing::factory()->create();
    Lifecycles::for($listing)->freeze(CarbonImmutable::parse('2026-10-02 11:00:00', 'UTC'));

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 11:00:00', 'UTC'));

    expect(Lifecycles::for($listing)->isFrozen())->toBeFalse()
        ->and(Lifecycles::for($listing)->frozenUntil())->toBeNull()
        ->and(Lifecycles::for($listing)->can('publish'))->toBeTrue()
        ->and(Lifecycles::for($listing)->unfreeze())->toBeFalse()
        ->and(LifecycleState::query()->sole()->frozen_at)->toBeNull();

    Event::assertNotDispatched(LifecycleUnfrozen::class);
});

it('freezes a subject that has no record yet', function (): void {
    $listing = Listing::factory()->create();
    LifecycleState::query()->delete();

    expect(Lifecycles::freeze(new FreezeRequest($listing, 'status')))->toBeTrue()
        ->and(LifecycleState::query()->sole()->version)->toBe(1);
});

it('refuses to freeze an unsaved subject', function (): void {
    Lifecycles::unfreeze(new UnfreezeRequest(new Listing, 'status'));
})->throws(SubjectNotPersistedException::class);

it('scopes frozen subjects and time in state', function (): void {
    $frozen = Listing::factory()->create();
    $lapsed = Listing::factory()->create();
    $plain = Listing::factory()->create();
    Lifecycles::for($frozen)->freeze(CarbonImmutable::parse('2026-10-03', 'UTC'));
    Lifecycles::for($lapsed)->freeze(CarbonImmutable::parse('2026-10-02 10:30:00', 'UTC'));

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC'));
    $plain->transition('publish');

    expect(Listing::query()->whereFrozen()->pluck('id')->all())->toBe([$frozen->id])
        ->and(Listing::query()->whereInStateFor('2 hours')->orderBy('id')->pluck('id')->all())->toBe([$frozen->id, $lapsed->id])
        ->and(Listing::query()->whereInStateFor('3 hours')->pluck('id')->all())->toBe([]);
});

it('records freezes under the fake', function (): void {
    $fake = Lifecycles::fake();
    $listing = Listing::factory()->create();

    $fake->assertNothingFrozen();

    expect(Lifecycles::for($listing)->freeze())->toBeTrue()
        ->and(Lifecycles::for($listing)->unfreeze())->toBeTrue();

    $fake->assertFrozen($listing);
    $fake->assertFrozen($listing, 'status');
    $fake->assertUnfrozen($listing);

    expect(fn () => $fake->assertNothingFrozen())->toThrow(ExpectationFailedException::class, '1 freeze(s)')
        ->and(fn () => $fake->assertFrozen($listing, 'other'))->toThrow(ExpectationFailedException::class, 'to be frozen')
        ->and(fn () => $fake->assertUnfrozen(Listing::factory()->create()))->toThrow(ExpectationFailedException::class, 'to be unfrozen');
});
