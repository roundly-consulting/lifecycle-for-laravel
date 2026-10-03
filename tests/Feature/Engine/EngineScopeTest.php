<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\DirectStateWriteException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownStateException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\DualDocument;

/**
 * The engine silences the model hooks only for the lifecycle it is writing: inside a handler,
 * hook or observer every other model — and every other lifecycle of the same model — keeps
 * strict writes, the declared-state check, adoption and the expiry-attribute sync.
 *
 * @param  Closure(TransitionContext): void  $handler
 */
function handledGo(Closure $handler): void
{
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->handledBy($handler)));
}

it('keeps strict writes for another subject inside a handler', function (): void {
    $other = Document::factory()->create();
    handledGo(function () use ($other): void {
        $other->setAttribute('status', 'c');
        $other->save();
    });
    $subject = Document::factory()->create();

    expect(fn () => $subject->transition('go'))->toThrow(DirectStateWriteException::class)
        ->and($subject->fresh()?->status)->toBe('a')
        ->and($other->fresh()?->status)->toBe('a');
});

it('refuses an undeclared state for another subject inside a handler, even when allowed', function (): void {
    $other = Document::factory()->create();
    handledGo(function () use ($other): void {
        Lifecycles::allowDirectWrites(function () use ($other): void {
            $other->setAttribute('status', 'not-a-state');
            $other->save();
        });
    });
    $subject = Document::factory()->create();

    expect(fn () => $subject->transition('go'))->toThrow(UnknownStateException::class)
        ->and($other->fresh()?->status)->toBe('a');
});

it('adopts an allowed write of another subject inside a handler', function (): void {
    $other = Document::factory()->create();
    handledGo(function () use ($other): void {
        Lifecycles::allowDirectWrites(function () use ($other): void {
            $other->setAttribute('status', 'b');
            $other->save();
        });
    });

    Document::factory()->create()->transition('go');

    expect($other->fresh()?->status)->toBe('b')
        ->and(LifecycleState::query()->where('subject_id', $other->id)->value('state'))->toBe('b')
        ->and(LifecycleTransition::query()->where('subject_id', $other->id)->where('kind', 'adopted')->count())->toBe(1);
});

it('refuses a handler that changes the state it is transitioning', function (bool $strict): void {
    config()->set('lifecycle.strict_writes', $strict);
    handledGo(function (TransitionContext $c): void {
        $c->subject->setAttribute('status', 'c');
    });
    $subject = Document::factory()->create();

    expect(fn () => $subject->transition('go'))->toThrow(InvalidLifecycleUsageException::class, 'status')
        ->and($subject->fresh()?->status)->toBe('a')
        ->and($subject->status)->toBe('a')
        ->and(LifecycleTransition::query()->where('kind', 'transition')->count())->toBe(0);
})->with(['strict' => true, 'not strict' => false]);

it('keeps strict writes for another lifecycle of the same subject inside a handler', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go
        ->handledBy(function (TransitionContext $c): void {
            if ($c->lifecycle === 'status') {
                $c->subject->setAttribute('review_status', 'c');
            }
        })));
    $subject = DualDocument::query()->create();

    expect(fn () => $subject->transition('go', lifecycle: 'status'))->toThrow(DirectStateWriteException::class)
        ->and($subject->fresh()?->status)->toBe('a')
        ->and($subject->fresh()?->review_status)->toBe('a');
});

it('follows another subject\'s expiry attribute saved inside a handler', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
    $holder = new stdClass;
    defineDocumentLifecycle(function (LifecycleBuilder $l) use ($holder): void {
        $l->states(['a', 'b', 'c', 'd'])->initial('a')->terminal('c', 'd');
        $l->state('b')->expiresAtAttribute('expires_at')->expiresVia('expire');
        $l->transition('go')->from('a')->to('b');
        $l->transition('expire')->from('b')->to('c')->systemOnly();
        $l->transition('pay')->from('a')->to('d')->handledBy(function () use ($holder): void {
            $holder->other->setAttribute('expires_at', CarbonImmutable::parse('2027-10-02 10:00:00', 'UTC'));
            $holder->other->save();
        });
    });
    $holder->other = Document::factory()->create(['expires_at' => CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC')]);
    $holder->other->transition('go');

    Document::factory()->create()->transition('pay');

    expect(Lifecycles::for($holder->other->fresh())->expiresAt()?->equalTo($holder->other->fresh()->expires_at))->toBeTrue()
        ->and(Lifecycles::for($holder->other->fresh())->expiresAt()?->year)->toBe(2027);

    Carbon::setTestNow();
});
