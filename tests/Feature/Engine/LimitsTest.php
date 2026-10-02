<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

function travel(string $instant): void
{
    Carbon::setTestNow(CarbonImmutable::parse($instant, 'UTC'));
}

it('refuses a stale expected version', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l));
    $document = Document::factory()->create();

    expect(Lifecycles::for($document)->expectingVersion(0)->check('go')->codes())->toBe(['stale_version'])
        ->and(Lifecycles::for($document)->expectingVersion(1)->apply('go')->record->version)->toBe(2)
        ->and(fn () => Lifecycles::for($document)->expectingVersion(1)->apply('finish'))->toThrow(TransitionDeniedException::class, 'changed in the meantime');
});

it('refuses transitions out of a sealed state unless they ignore the seal', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l, finish: fn (TransitionBuilder $finish) => $finish->ignoresSeal());
        $l->transition('back')->from('b')->to('a');
        $l->state('b')->sealedAfter('1 day');
    });
    $document = Document::factory()->create();
    $document->transition('go');

    travel('2026-10-03 09:59:59');
    expect(Lifecycles::for($document)->can('back'))->toBeTrue();

    travel('2026-10-03 10:00:00');
    expect(Lifecycles::for($document)->check('back')->codes())->toBe(['sealed'])
        ->and(Lifecycles::for($document)->asSystem()->check('back')->codes())->toBe(['sealed', 'system_not_allowed'])
        ->and(Lifecycles::for($document)->can('finish'))->toBeTrue();
});

it('enforces a minimum dwell for users only, with the instant it ends', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l, finish: fn (TransitionBuilder $finish) => $finish->allowSystem());
        $l->transition('rush')->from('b')->to('a')->ignoresMinDwell();
        $l->state('b')->minDwell('2 hours');
    });
    $document = Document::factory()->create();
    $document->transition('go');

    $decision = Lifecycles::for($document)->check('finish');

    expect($decision->codes())->toBe(['min_dwell_not_reached'])
        ->and($decision->retryAfter?->toDateTimeString())->toBe('2026-10-02 12:00:00')
        ->and(Lifecycles::for($document)->asSystem()->can('finish'))->toBeTrue()
        ->and(Lifecycles::for($document)->can('rush'))->toBeTrue();

    travel('2026-10-02 12:00:00');

    expect(Lifecycles::for($document)->can('finish'))->toBeTrue();
});

it('enforces a cooldown between occurrences for users only', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l);
        $l->transition('back')->from('b')->to('a')->cooldown('1 hour')->allowSystem();
    });
    $document = Document::factory()->create();
    $document->transition('go');
    $document->transition('back');
    $document->transition('go');

    travel('2026-10-02 10:59:59');
    $decision = Lifecycles::for($document)->check('back');

    expect($decision->codes())->toBe(['cooldown_active'])
        ->and($decision->retryAfter?->toDateTimeString())->toBe('2026-10-02 11:00:00')
        ->and($decision->isRetryable())->toBeTrue()
        ->and(Lifecycles::for($document)->asSystem()->can('back'))->toBeTrue();

    travel('2026-10-02 11:00:00');

    expect(Lifecycles::for($document)->can('back'))->toBeTrue();
});

it('caps occurrences in every context from the record counters', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l);
        $l->transition('back')->from('b')->to('a')->maxOccurrences(2)->allowSystem();
    });
    $document = Document::factory()->create();

    foreach (range(1, 2) as $round) {
        $document->transition('go');
        $document->transition('back');
    }

    $document->transition('go');

    expect(Lifecycles::for($document)->check('back')->codes())->toBe(['max_occurrences_reached'])
        ->and(Lifecycles::for($document)->check('back')->first()?->params['max'])->toBe(2)
        ->and(Lifecycles::for($document)->asSystem()->check('back')->codes())->toBe(['max_occurrences_reached']);

    // Pruning history cannot reset a limit: the counters live on the record.
    LifecycleTransition::query()->toBase()->delete();

    expect(Lifecycles::for($document)->can('back'))->toBeFalse();
});
