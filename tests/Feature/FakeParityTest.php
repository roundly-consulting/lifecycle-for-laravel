<?php

declare(strict_types=1);

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
        $l->transition('ab')->from('a')->to('b');
        $l->transition('also_ab')->from('a')->to('b');
        $l->transition('bc')->from('b')->to('c');
        $l->transition('touch')->from('b')->to('b')->allowSelf();
        $l->transition('close')->from('*')->to('z');
        $l->transition('system')->from('a')->to('c')->systemOnly();
    });
}

/**
 * @return array{0: string, 1: list<string>, 2: string}
 */
function parityRun(Closure $scenario): array
{
    $document = Document::factory()->create();
    $outcome = 'ok';
    $codes = [];

    try {
        $scenario($document);
    } catch (Throwable $exception) {
        $outcome = $exception::class;
        $codes = method_exists($exception, 'decision') ? $exception->decision()->codes() : [];
    }

    return [$outcome, $codes, (string) $document->getAttributes()['status']];
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
]);

it('gives the same outcome on the real manager and the fake', function (Closure $scenario): void {
    parityLifecycle();
    $real = parityRun($scenario);

    Lifecycles::fake();
    $fake = parityRun($scenario);

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
