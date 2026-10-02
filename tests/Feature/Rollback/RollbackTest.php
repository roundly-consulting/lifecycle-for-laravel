<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackRequest;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Events\LifecycleRolledBack;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Exceptions\RollbackDeniedException;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectNotPersistedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Handlers\CompensatingHandler;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Handlers\PlainHandler;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

function chain(?Closure $configure = null): Document
{
    defineDocumentLifecycle(function (LifecycleBuilder $l) use ($configure): void {
        $l->states(['a', 'b', 'c', 'd'])->initial('a')->terminal('d');
        $l->transition('ab')->from('a')->to('b');
        $l->transition('bc')->from('b')->to('c');
        $l->transition('cd')->from('c')->to('d');

        if ($configure !== null) {
            $configure($l);
        }
    });

    return Document::factory()->create();
}

function rollbackCodes(Closure $call): array
{
    try {
        $call();
    } catch (RollbackDeniedException $exception) {
        return $exception->decision()->codes();
    }

    return [];
}

it('undoes the last transition and then the one before', function (): void {
    Event::fake([LifecycleRolledBack::class, LifecycleTransitioned::class]);
    $document = chain();
    $document->transition('ab');
    $document->transition('bc');

    $first = Lifecycles::for($document)->because('mistake')->rollback();

    expect($first->from)->toBe('c')
        ->and($first->to)->toBe('b')
        ->and($first->reverted[0]->transition)->toBe('bc')
        ->and($first->reverted[0]->reverted)->toBeTrue()
        ->and($first->records[0]->kind)->toBe(TransitionKind::Rollback)
        ->and($first->records[0]->revertsId)->toBe($first->reverted[0]->id)
        ->and($first->records[0]->reason)->toBe('mistake')
        ->and($document->status)->toBe('b')
        ->and($document->fresh()?->status)->toBe('b');

    $second = Lifecycles::for($document)->rollback();

    expect($second->reverted[0]->transition)->toBe('ab')
        ->and($document->status)->toBe('a')
        ->and(rollbackCodes(fn () => Lifecycles::for($document)->rollback()))->toBe(['not_reversible']);

    Event::assertDispatchedTimes(LifecycleRolledBack::class, 2);
    Event::assertDispatched(LifecycleTransitioned::class, fn (LifecycleTransitioned $e): bool => $e->kind === TransitionKind::Rollback && $e->transition === 'bc');
});

it('never rolls back a rollback', function (): void {
    $document = chain();
    $document->transition('ab');
    Lifecycles::for($document)->rollback();

    $rollbackRow = LifecycleTransition::query()->where('kind', 'rollback')->sole();

    expect(rollbackCodes(fn () => Lifecycles::for($document)->rollbackTo($rollbackRow->id)))->toBe(['not_on_path'])
        ->and(Lifecycles::for($document)->history()->first()?->kind)->toBe(TransitionKind::Rollback);
});

it('rolls back several rows to a point', function (): void {
    $document = chain();
    $initial = LifecycleTransition::query()->sole();
    $document->transition('ab');
    $document->transition('bc');

    $result = Lifecycles::for($document)->rollbackTo($initial->toRecord(Lifecycles::for($document)->definition()));

    expect($result->from)->toBe('c')
        ->and($result->to)->toBe('a')
        ->and(array_map(fn ($r) => $r->transition, $result->reverted))->toBe(['bc', 'ab'])
        ->and($result->records)->toHaveCount(2)
        ->and($document->fresh()?->status)->toBe('a')
        ->and(LifecycleState::query()->sole()->version)->toBe(5);
});

it('changes nothing when any row of a rollbackTo is refused', function (): void {
    $document = chain(function (LifecycleBuilder $l): void {
        $l->transition('bc_locked')->from('b')->to('c')->irreversible();
    });
    $initial = LifecycleTransition::query()->sole()->id;
    $document->transition('ab');
    $document->transition('bc_locked');

    expect(rollbackCodes(fn () => Lifecycles::for($document)->rollbackTo($initial)))->toBe(['irreversible'])
        ->and($document->fresh()?->status)->toBe('c')
        ->and(LifecycleTransition::query()->where('kind', 'rollback')->count())->toBe(0);
});

it('refuses a point of another subject or lifecycle', function (): void {
    $document = chain();
    $other = Document::factory()->create();
    $document->transition('ab');

    $foreign = LifecycleTransition::query()->where('subject_id', $other->id)->sole()->id;

    expect(rollbackCodes(fn () => Lifecycles::for($document)->rollbackTo($foreign)))->toBe(['not_on_path'])
        ->and(rollbackCodes(fn () => Lifecycles::for($document)->rollbackTo(999)))->toBe(['not_on_path']);
});

it('refuses to roll back to the current top or with nothing done', function (): void {
    $document = chain();
    $initial = LifecycleTransition::query()->sole()->id;

    expect(rollbackCodes(fn () => Lifecycles::for($document)->rollbackTo($initial)))->toBe(['nothing_to_rollback'])
        ->and(rollbackCodes(fn () => Lifecycles::for($document)->rollback()))->toBe(['not_reversible']);

    LifecycleTransition::query()->toBase()->delete();

    expect(rollbackCodes(fn () => Lifecycles::for($document)->rollback()))->toBe(['nothing_to_rollback'])
        ->and(Lifecycles::for($document)->canRollback()->codes())->toBe(['nothing_to_rollback']);
});

it('bounds the steps of one rollback', function (): void {
    config()->set('lifecycle.rollback.max_steps', 1);
    $document = chain();
    $initial = LifecycleTransition::query()->sole()->id;
    $document->transition('ab');
    $document->transition('bc');

    Lifecycles::for($document)->rollbackTo($initial);
})->throws(InvalidLifecycleUsageException::class, 'at most 1 rows');

it('respects the rollback window, its own or the default', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        $l->states(['a', 'b', 'c'])->initial('a')->terminal('c');
        $l->transition('ab')->from('a')->to('b')->reversible('1 hour');
        $l->transition('bc')->from('b')->to('c');
    });
    $document = Document::factory()->create();
    $document->transition('ab');

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:59:59', 'UTC'));
    expect(Lifecycles::for($document)->canRollback()->allowed)->toBeTrue();

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 11:00:00', 'UTC'));
    expect(Lifecycles::for($document)->canRollback()->codes())->toBe(['rollback_window_passed']);

    config()->set('lifecycle.rollback.default_window', '1 day');
    $document->transition('bc');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-03 11:00:00', 'UTC'));

    expect(Lifecycles::for($document)->canRollback()->codes())->toBe(['rollback_window_passed']);
});

it('refuses irreversible transitions and handlers that cannot compensate', function (): void {
    $document = chain(function (LifecycleBuilder $l): void {
        $l->transition('ab_plain')->from('a')->to('b')->handledBy(PlainHandler::class);
    });
    $document->transition('ab_plain');

    expect(Lifecycles::for($document)->canRollback()->codes())->toBe(['not_reversible']);
});

it('compensates the handler and restores snapshots and stamps', function (): void {
    $handler = new CompensatingHandler;
    app()->instance(CompensatingHandler::class, $handler);
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        $l->states(['a', 'b'])->initial('a')->terminal('b');
        $l->state('b')->stamps('published_at');
        $l->transition('publish')->from('a')->to('b')->snapshots('price')->handledBy(CompensatingHandler::class);
    });
    $document = Document::factory()->create(['price' => '10.00']);
    $document->transition('publish');
    $document->update(['title' => 'edited after']);

    Lifecycles::for($document)->rollback();

    expect($handler->calls)->toBe(['handle:publish', 'compensate:publish'])
        ->and($document->fresh()?->published_at)->toBeNull()
        ->and($document->fresh()?->title)->toBe('edited after');
});

it('refuses a rollback over a later edit unless forced', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        $l->states(['a', 'b'])->initial('a')->terminal('b');
        $l->transition('price')->from('a')->to('b')->snapshots('price')
            ->handledBy(fn ($c) => $c->subject->setAttribute('price', '20.00'))->reversible(withoutCompensation: true);
    });
    $document = Document::factory()->create(['price' => '10.00']);
    $document->transition('price');
    $document->update(['price' => '25.00']);

    expect(rollbackCodes(fn () => Lifecycles::for($document)->rollback()))->toBe(['rollback_conflict']);

    Lifecycles::for($document)->rollback(force: true);

    expect((float) $document->fresh()?->price)->toBe(10.0);
});

it('restores counters and the entry time', function (): void {
    $document = chain(function (LifecycleBuilder $l): void {
        $l->transition('ba')->from('b')->to('a')->maxOccurrences(1);
    });
    $document->transition('ab');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC'));
    $document->transition('ba');

    expect(Lifecycles::for($document)->can('ab'))->toBeTrue();

    $document->transition('ab');

    expect(Lifecycles::for($document)->check('ba')->codes())->toBe(['max_occurrences_reached']);

    Lifecycles::for($document)->rollback();
    Lifecycles::for($document)->rollback();

    expect(Lifecycles::for($document)->can('ab'))->toBeFalse()
        ->and(Lifecycles::for($document)->state())->toBe('b')
        ->and(Lifecycles::for($document)->enteredAt()?->toDateTimeString())->toBe('2026-10-02 10:00:00')
        ->and(LifecycleState::query()->sole()->counters)->toBe(['ab' => ['count' => 1, 'last_at' => '2026-10-02 10:00:00']])
        ->and($document->transition('ba')->to)->toBe('a');
});

it('refuses to roll back into a full quota', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->state('b')->quota(1);
        $l->transition('back')->from('b')->to('a');
        $l->transition('again')->from('a')->to('b');
    });
    $first = Document::factory()->create();
    $second = Document::factory()->create();
    $first->transition('go');
    $first->transition('back');
    $second->transition('go');

    expect(rollbackCodes(fn () => Lifecycles::for($first)->rollback()))->toBe(['quota_exceeded']);
});

it('refuses while frozen or sealed and when the state no longer matches', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l);
        $l->state('b')->sealedAfter('1 hour');
    });
    $document = Document::factory()->create();
    $document->transition('go');

    Lifecycles::for($document)->freeze();
    expect(Lifecycles::for($document)->canRollback()->codes())->toBe(['frozen']);
    Lifecycles::for($document)->unfreeze();

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 11:00:00', 'UTC'));
    expect(Lifecycles::for($document)->canRollback()->codes())->toBe(['sealed']);

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:30:00', 'UTC'));
    Document::query()->whereKey($document->id)->update(['status' => 'a']);
    LifecycleState::query()->update(['state' => 'a']);

    expect(Lifecycles::for($document->fresh())->canRollback()->codes())->toBe(['state_mismatch']);
});

it('refuses an unsaved subject', function (): void {
    Lifecycles::checkRollback(new RollbackRequest(new Document, 'status'));
})->throws(SubjectNotPersistedException::class);
