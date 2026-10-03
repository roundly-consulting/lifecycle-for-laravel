<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\DirectStateWriteException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Lifecycle\Testing\LifecycleFake;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

/**
 * The fake evaluates the real compiled definition's structural checks: every structural
 * scenario gives the same outcome, denial codes and in-memory state on the real manager
 * and on the fake.
 */
function parityLifecycle(): void
{
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        $l->states(['a', 'b', 'c', 'z'])->initial('a')->terminal('z');
        $l->state('b')->ttl('1 day')->expiresVia('bc');
        $l->transition('ab')->from('a')->to('b');
        $l->transition('also_ab')->from('a')->to('b');
        $l->transition('bc')->from('b')->to('c')->allowSystem();
        $l->transition('touch')->from('b')->to('b')->allowSelf();
        $l->transition('close')->from('*')->to('z');
        $l->transition('system')->from('a')->to('c')->systemOnly();
        $l->transition('thaw')->from('a')->to('c')->ignoresFreeze();
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
