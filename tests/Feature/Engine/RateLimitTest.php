<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Enums\RateLimitScope;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

function bouncingDocument(RateLimitScope $per = RateLimitScope::Actor, int $max = 2): Document
{
    defineDocumentLifecycle(function (LifecycleBuilder $l) use ($per, $max): void {
        baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->rateLimit($max, '1 hour', $per)->allowSystem());
        $l->transition('back')->from('b')->to('a');
    });

    return Document::factory()->create();
}

it('consumes one hit per applied transition and refuses beyond the limit', function (): void {
    $document = bouncingDocument();
    $user = User::factory()->create();

    foreach (range(1, 2) as $round) {
        expect(Lifecycles::for($document)->by($user)->can('go'))->toBeTrue();
        Lifecycles::for($document)->by($user)->apply('go');
        $document->transition('back');
    }

    $decision = Lifecycles::for($document)->by($user)->check('go');

    expect($decision->codes())->toBe(['rate_limited'])
        ->and($decision->retryAfter?->toDateTimeString())->toBe('2026-10-02 11:00:00')
        ->and(fn () => Lifecycles::for($document)->by($user)->apply('go'))->toThrow(TransitionDeniedException::class, 'Too many attempts')
        ->and(Lifecycles::for($document)->asSystem()->can('go'))->toBeTrue()
        ->and(Lifecycles::for($document)->by(User::factory()->create())->can('go'))->toBeTrue();
});

it('never consumes a hit on check or on a denied apply', function (): void {
    $document = bouncingDocument(max: 1);
    $user = User::factory()->create();

    Lifecycles::for($document)->by($user)->check('go');
    Lifecycles::for($document)->by($user)->check('go');
    Lifecycles::for($document)->by($user)->freeze();
    rescue(fn () => Lifecycles::for($document)->by($user)->apply('go'), report: false);
    Lifecycles::for($document)->unfreeze();

    expect(Lifecycles::for($document)->by($user)->apply('go')->to)->toBe('b');
});

it('counts per subject across actors', function (): void {
    $document = bouncingDocument(RateLimitScope::Subject, 1);

    Lifecycles::for($document)->by(User::factory()->create())->apply('go');
    $document->transition('back');

    expect(Lifecycles::for($document)->by(User::factory()->create())->check('go')->codes())->toBe(['rate_limited']);
});

it('counts per actor and subject', function (): void {
    $document = bouncingDocument(RateLimitScope::ActorAndSubject, 1);
    $user = User::factory()->create();

    Lifecycles::for($document)->by($user)->apply('go');
    $document->transition('back');

    expect(Lifecycles::for($document)->by($user)->can('go'))->toBeFalse()
        ->and(Lifecycles::for($document)->by(User::factory()->create())->can('go'))->toBeTrue();
});
