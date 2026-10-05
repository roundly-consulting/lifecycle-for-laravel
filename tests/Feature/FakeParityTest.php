<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\DirectStateWriteException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Lifecycle\Testing\LifecycleFake;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

/**
 * The fake evaluates the real compiled definition's structural checks: every structural
 * scenario gives the same outcome, denial codes and in-memory state on the real manager
 * and on the fake.
 */
function parityLifecycle(): void
{
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        $l->states(['a', 'b', 'c', 'd', 'z'])->initial('a')->terminal('z');
        $l->state('b')->ttl('1 day')->expiresVia('bc');
        $l->transition('ab')->from('a')->to('b');
        $l->transition('also_ab')->from('a')->to('b');
        $l->transition('bc')->from('b')->to('c')->allowSystem();
        $l->transition('touch')->from('b')->to('b')->allowSelf();
        $l->transition('close')->from('*')->to('z');
        $l->transition('system')->from('a')->to('c')->systemOnly();
        $l->transition('thaw')->from('a')->to('c')->ignoresFreeze();
        $l->transition('needs_actor')->from('a')->to('c')->requiresActor();
        $l->transition('needs_reason')->from('a')->to('c')->requiresReason();
        $l->transition('with_rules')->from('a')->to('c')->allowSystem()
            ->rules(['card' => 'required', 'amount' => 'required|integer'])->sensitive('card');
        $l->transition('secret')->from('a')->to('c')->allowSystem()->rules(['token' => 'required'])->sensitive('token');
        $l->transition('lock')->from('a')->to('c')->irreversible();
        $l->transition('handled')->from('a')->to('c')->handledBy(static function (): void {});
        $l->transition('timed')->from('a')->to('c')->reversible(within: '1 hour');
        $l->transition('out')->from('c')->to('d')->ignoresFreeze();
    });
}

/**
 * The outcome, denial codes, in-memory state and return value of a scenario.
 *
 * @return array{0: string, 1: list<string>, 2: string, 3: mixed}
 */
function parityRun(Closure $scenario): array
{
    $document = Document::factory()->create();
    $outcome = 'ok';
    $codes = [];
    $returned = null;

    try {
        $returned = $scenario($document);
    } catch (Throwable $exception) {
        $outcome = $exception::class;
        $codes = method_exists($exception, 'decision') ? $exception->decision()->codes() : [];
    }

    $returned = match (true) {
        $returned instanceof CarbonInterface => $returned->toIso8601String(),
        $returned instanceof Decision => implode(',', $returned->codes()),
        $returned instanceof TransitionResult => $returned->transition.($returned->replayed ? ' (replayed)' : ''),
        is_array($returned) => json_encode($returned, JSON_THROW_ON_ERROR),
        is_bool($returned), $returned === null => $returned,
        default => get_debug_type($returned),
    };

    return [$outcome, $codes, (string) $document->getAttributes()['status'], $returned];
}

dataset('structural scenarios', [
    'success' => [fn (Document $d) => Lifecycles::for($d)->apply('ab')],
    'unknown transition' => [fn (Document $d) => Lifecycles::for($d)->apply('nope')],
    'wrong source' => [fn (Document $d) => Lifecycles::for($d)->apply('bc')],
    'terminal' => [function (Document $d): void {
        Lifecycles::for($d)->apply('close');
        Lifecycles::for($d)->apply('ab');
    }],
    'wildcard' => [function (Document $d): void {
        Lifecycles::for($d)->apply('ab');
        Lifecycles::for($d)->apply('close');
    }],
    'self' => [function (Document $d): void {
        Lifecycles::for($d)->apply('ab');
        Lifecycles::for($d)->apply('touch');
    }],
    'ambiguous target' => [fn (Document $d) => Lifecycles::for($d)->transitionTo('b')],
    'no transition to target' => [fn (Document $d) => Lifecycles::for($d)->transitionTo('a')],
    'system-only in user context' => [fn (Document $d) => Lifecycles::for($d)->apply('system')],
    'system context on a user transition' => [fn (Document $d) => Lifecycles::for($d)->asSystem()->apply('ab')],
    'system-only in system context' => [fn (Document $d) => Lifecycles::for($d)->asSystem()->apply('system')],
    'payload without rules' => [fn (Document $d) => Lifecycles::for($d)->with(['note' => 'x'])->apply('ab')],
    'schedule an unknown transition' => [fn (Document $d) => Lifecycles::for($d)->asSystem()->schedule('nope', CarbonImmutable::now()->addDay())],
    'schedule a system-only transition in user context' => [fn (Document $d) => Lifecycles::for($d)->schedule('system', CarbonImmutable::now()->addDay())],
    'schedule a user transition' => [fn (Document $d) => Lifecycles::for($d)->schedule('ab', CarbonImmutable::now()->addDay())],
    'schedule from a terminal state' => [function (Document $d): void {
        Lifecycles::for($d)->apply('close');
        Lifecycles::for($d)->asSystem()->schedule('bc', CarbonImmutable::now()->addDay());
    }],
    'schedule a payload without rules' => [fn (Document $d) => Lifecycles::for($d)->asSystem()->with(['note' => 'x'])->schedule('system', CarbonImmutable::now()->addDay())],
    'apply while frozen' => [function (Document $d): void {
        Lifecycles::for($d)->freeze();
        Lifecycles::for($d)->apply('ab');
    }],
    'apply ignoresFreeze while frozen' => [function (Document $d): void {
        Lifecycles::for($d)->freeze();
        Lifecycles::for($d)->apply('thaw');
    }],
    'apply after the freeze lapsed' => [function (Document $d): void {
        Lifecycles::for($d)->freeze(CarbonImmutable::now()->addHour());
        Carbon::setTestNow(CarbonImmutable::now()->addHours(2));
        Lifecycles::for($d)->apply('ab');
    }],
    'apply after unfreezing' => [function (Document $d): bool {
        Lifecycles::for($d)->freeze();
        $unfrozen = Lifecycles::for($d)->unfreeze();
        Lifecycles::for($d)->apply('ab');

        return $unfrozen;
    }],
    'freeze twice alike' => [function (Document $d): bool {
        Lifecycles::for($d)->freeze();

        return Lifecycles::for($d)->freeze();
    }],
    'freeze again with another reason' => [function (Document $d): bool {
        Lifecycles::for($d)->freeze();

        return Lifecycles::for($d)->because('changed')->freeze();
    }],
    'unfreeze without a freeze' => [fn (Document $d): bool => Lifecycles::for($d)->unfreeze()],
    'roll back while frozen' => [function (Document $d): void {
        Lifecycles::for($d)->apply('ab');
        Lifecycles::for($d)->freeze();
        Lifecycles::for($d)->rollback();
    }],
    'cancel without a schedule' => [fn (Document $d): bool => Lifecycles::for($d)->cancelScheduled('bc')],
    'cancel a schedule' => [function (Document $d): bool {
        Lifecycles::for($d)->apply('ab');
        Lifecycles::for($d)->asSystem()->schedule('bc', CarbonImmutable::now()->addDay());

        return Lifecycles::for($d)->cancelScheduled('bc');
    }],
    'cancel a schedule twice' => [function (Document $d): bool {
        Lifecycles::for($d)->apply('ab');
        Lifecycles::for($d)->asSystem()->schedule('bc', CarbonImmutable::now()->addDay());
        Lifecycles::for($d)->cancelScheduled('bc');

        return Lifecycles::for($d)->cancelScheduled('bc');
    }],
    'cancel a schedule whose state was left' => [function (Document $d): bool {
        Lifecycles::for($d)->asSystem()->schedule('system', CarbonImmutable::now()->addDay());
        Lifecycles::for($d)->apply('ab');

        return Lifecycles::for($d)->cancelScheduled('system');
    }],
    'renew without an interval' => [function (Document $d): CarbonInterface {
        Lifecycles::for($d)->apply('ab');

        return Lifecycles::for($d)->renew();
    }],
    'renew with an interval' => [function (Document $d): CarbonInterface {
        Lifecycles::for($d)->apply('ab');

        return Lifecycles::for($d)->renew('3 days');
    }],
    'renew a state without expiry' => [fn (Document $d): CarbonInterface => Lifecycles::for($d)->renew()],
    'clear the expiry of a state without expiry' => [fn (Document $d): bool => Lifecycles::for($d)->neverExpire()],
    'actor required' => [fn (Document $d) => Lifecycles::for($d)->apply('needs_actor')],
    'reason required' => [fn (Document $d) => Lifecycles::for($d)->apply('needs_reason')],
    'reason given' => [fn (Document $d) => Lifecycles::for($d)->because('ok')->apply('needs_reason')],
    'reason too long' => [function (Document $d) {
        config()->set('lifecycle.history.reason_max_length', 3);

        return Lifecycles::for($d)->because('too long')->apply('needs_reason');
    }],
    'invalid payload' => [fn (Document $d) => Lifecycles::for($d)->with(['card' => '4242'])->apply('with_rules')],
    'valid payload' => [fn (Document $d) => Lifecycles::for($d)->with(['card' => '4242', 'amount' => 5])->apply('with_rules')],
    'check without the payload' => [fn (Document $d) => Lifecycles::for($d)->check('with_rules')],
    'check an invalid payload' => [fn (Document $d) => Lifecycles::for($d)->with(['amount' => 'x'])->check('with_rules')],
    'schedule a required sensitive key' => [fn (Document $d) => Lifecycles::for($d)->asSystem()->with(['token' => 't'])->schedule('secret', CarbonImmutable::now()->addDay())],
    'schedule an invalid payload' => [fn (Document $d) => Lifecycles::for($d)->asSystem()->with(['amount' => 'x'])->schedule('with_rules', CarbonImmutable::now()->addDay())],
    'dirty lifecycle attribute' => [function (Document $d) {
        $d->setAttribute('status', 'b');

        return Lifecycles::for($d)->apply('bc');
    }],
    'idempotent replay' => [function (Document $d) {
        Lifecycles::for($d)->idempotencyKey('k1')->apply('ab');

        return Lifecycles::for($d)->idempotencyKey('k1')->apply('ab');
    }],
    'idempotency key reused for another transition' => [function (Document $d) {
        Lifecycles::for($d)->idempotencyKey('k1')->apply('ab');

        return Lifecycles::for($d)->idempotencyKey('k1')->apply('bc');
    }],
    'idempotency key reused for another target' => [function (Document $d) {
        Lifecycles::for($d)->idempotencyKey('k1')->apply('ab');

        return Lifecycles::for($d)->idempotencyKey('k1')->transitionTo('c');
    }],
    'apply to a soft-deleted subject' => [function (Document $d) {
        $d->delete();

        return Lifecycles::for($d)->apply('ab');
    }],
    'check a soft-deleted subject' => [function (Document $d) {
        $d->delete();

        return Lifecycles::for($d)->check('ab');
    }],
    'freeze a soft-deleted subject' => [function (Document $d) {
        $d->delete();

        return Lifecycles::for($d)->freeze();
    }],
    'schedule on a soft-deleted subject' => [function (Document $d) {
        $d->delete();

        return Lifecycles::for($d)->asSystem()->schedule('system', CarbonImmutable::now()->addDay());
    }],
    'freeze with a reason too long' => [function (Document $d) {
        config()->set('lifecycle.history.reason_max_length', 3);

        return Lifecycles::for($d)->because('too long')->freeze();
    }],
    'apply to a model without the attribute loaded' => [fn (Document $d) => Lifecycles::for(Document::query()->select('id')->findOrFail($d->id))->apply('ab')],
    'apply to a model whose state is still NULL' => [function (Document $d) {
        Document::query()->whereKey($d->id)->toBase()->update(['status' => null]);

        return Lifecycles::for(Document::query()->findOrFail($d->id))->apply('ab');
    }],
    'roll back an irreversible transition' => [function (Document $d) {
        Lifecycles::for($d)->apply('lock');

        return Lifecycles::for($d)->rollback();
    }],
    'roll back a handler that cannot compensate' => [function (Document $d) {
        Lifecycles::for($d)->apply('handled');

        return Lifecycles::for($d)->rollback();
    }],
    'roll back after the window' => [function (Document $d) {
        Lifecycles::for($d)->apply('timed');
        Carbon::setTestNow(CarbonImmutable::now()->addHours(2));

        return Lifecycles::for($d)->canRollback();
    }],
    'roll back within the window' => [function (Document $d) {
        Lifecycles::for($d)->apply('timed');

        return Lifecycles::for($d)->canRollback();
    }],
    'roll back a system-only transition from user context' => [function (Document $d) {
        Lifecycles::for($d)->asSystem()->apply('system');

        return Lifecycles::for($d)->rollback();
    }],
    'roll back a system-only transition as the system' => [function (Document $d) {
        Lifecycles::for($d)->asSystem()->apply('system');

        return Lifecycles::for($d)->asSystem()->canRollback();
    }],
    'roll back with a reason too long' => [function (Document $d) {
        config()->set('lifecycle.history.reason_max_length', 3);
        Lifecycles::for($d)->apply('ab');

        return Lifecycles::for($d)->because('too long')->rollback();
    }],
    'roll back through a transition that respects the freeze' => [function (Document $d) {
        $point = Lifecycles::for($d)->apply('ab');
        Lifecycles::for($d)->apply('bc');
        Lifecycles::for($d)->apply('out');
        Lifecycles::for($d)->freeze();

        return [Lifecycles::for($d)->canRollback()->allowed, Lifecycles::for($d)->canRollbackTo($point->record)->codes()];
    }],
    'roll back a soft-deleted subject' => [function (Document $d) {
        Lifecycles::for($d)->apply('ab');
        $d->delete();

        return Lifecycles::for($d)->rollback();
    }],
    'check the rollback of a soft-deleted subject' => [function (Document $d) {
        Lifecycles::for($d)->apply('ab');
        $d->delete();

        return Lifecycles::for($d)->canRollback();
    }],
    'reopen what the reverted transition cancelled' => [function (Document $d): bool {
        Lifecycles::for($d)->asSystem()->schedule('system', CarbonImmutable::now()->addDay());
        Lifecycles::for($d)->apply('ab');
        Lifecycles::for($d)->rollback();

        return Lifecycles::for($d)->cancelScheduled('system');
    }],
    'forget what was scheduled in the state a rollback leaves' => [function (Document $d): bool {
        Lifecycles::for($d)->apply('ab');
        Lifecycles::for($d)->asSystem()->schedule('bc', CarbonImmutable::now()->addDay());
        Lifecycles::for($d)->rollback();

        return Lifecycles::for($d)->cancelScheduled('bc');
    }],
    'roll back after a direct write' => [function (Document $d) {
        Lifecycles::for($d)->apply('ab');
        Lifecycles::allowDirectWrites(fn () => $d->update(['status' => 'c']));

        return Lifecycles::for($d)->rollback();
    }],
    'check the rollback after a direct write' => [function (Document $d) {
        $ab = Lifecycles::for($d)->apply('ab');
        $bc = Lifecycles::for($d)->apply('bc');
        Lifecycles::allowDirectWrites(fn () => $d->update(['status' => 'd']));

        return [
            Lifecycles::for($d)->canRollback()->codes(),
            Lifecycles::for($d)->canRollbackTo($bc->record)->codes(),
            Lifecycles::for($d)->canRollbackTo($ab->record)->codes(),
        ];
    }],
    'roll back an ignoresFreeze transition while frozen after a direct write' => [function (Document $d) {
        Lifecycles::for($d)->apply('thaw');
        Lifecycles::allowDirectWrites(fn () => $d->update(['status' => 'd']));
        Lifecycles::for($d)->freeze();

        return Lifecycles::for($d)->canRollback();
    }],
    'roll back after a write that bypassed the engine' => [function (Document $d) {
        Lifecycles::for($d)->apply('ab');
        Document::query()->whereKey($d->id)->update(['status' => 'c']);
        $d->refresh();

        return Lifecycles::for($d)->rollback();
    }],
    'expire at an instant' => [function (Document $d): CarbonInterface {
        Lifecycles::for($d)->apply('ab');

        return Lifecycles::for($d)->expireAt(CarbonImmutable::parse('2026-12-24 18:00:00', 'Europe/Bratislava'));
    }],
]);

it('gives the same outcome on the real manager and the fake', function (Closure $scenario): void {
    parityLifecycle();
    $now = CarbonImmutable::parse('2026-10-03 10:00:00', 'UTC');
    Carbon::setTestNow($now);
    $real = parityRun($scenario);

    Carbon::setTestNow($now);
    Lifecycles::fake();
    $fake = parityRun($scenario);
    Carbon::setTestNow();

    expect($fake)->toBe($real);
})->with('structural scenarios');

it('hands the fake to code that injects the manager', function (): void {
    $fake = Lifecycles::fake();
    $consumer = new class(app(LifecycleManager::class))
    {
        public function __construct(public LifecycleManager $lifecycle) {}
    };

    expect($consumer->lifecycle)->toBe($fake)
        ->and($consumer->lifecycle)->toBeInstanceOf(LifecycleFake::class);
});

it('starts a model created under the fake in its initial state and still refuses direct writes', function (): void {
    parityLifecycle();
    Lifecycles::fake();
    $document = Document::factory()->create();

    expect($document->status)->toBe('a');

    $document->status = 'b';

    expect(fn () => $document->save())->toThrow(DirectStateWriteException::class);
});

it('returns the same record shape from the fake as from the real manager', function (bool $storePayload): void {
    parityLifecycle();
    config()->set('lifecycle.history.store_payload', $storePayload);
    $user = User::factory()->create();
    $this->actingAs($user);
    $shape = function (): array {
        $record = Lifecycles::for(Document::factory()->create())->with(['card' => '4242', 'amount' => 5])->apply('with_rules')->record;

        return [$record->actorType, $record->actorId, $record->context, $record->system];
    };

    $real = $shape();
    Lifecycles::fake();

    expect($shape())->toBe($real)
        ->and($real)->toBe([$user->getMorphClass(), $user->id, $storePayload ? ['amount' => 5] : [], false]);
})->with(['payload stored' => true, 'payload not stored' => false]);

it('returns the same rollback result shape from the fake as from the real manager', function (): void {
    parityLifecycle();
    $user = User::factory()->create();
    $shape = function () use ($user): array {
        $document = Document::factory()->create();
        Lifecycles::for($document)->apply('ab');
        Lifecycles::for($document)->apply('bc');
        $result = Lifecycles::for($document)->by($user)->because('undo')->rollback();

        return [
            array_map(fn ($record): array => [$record->transition, $record->reverted], $result->reverted),
            array_map(fn ($record): array => [$record->kind->value, $record->transition, $record->from, $record->to, $record->actorId, $record->reason, $record->revertsId === $result->reverted[0]->id], $result->records),
            [$result->from, $result->to],
        ];
    };

    $real = $shape();
    Lifecycles::fake();

    expect($shape())->toBe($real)
        ->and($real[1])->toBe([['rollback', 'bc', 'c', 'b', $user->id, 'undo', true]]);
});
