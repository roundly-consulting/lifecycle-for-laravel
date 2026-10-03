<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\RateLimiter;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Engine\GuardPipeline;
use RoundlyConsulting\Lifecycle\Enums\RateLimitScope;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

/**
 * Rate-limit keys are fixed parts — prefix, subject type, lifecycle, transition, actor type and
 * key, subject key — each escaped (anything outside `[A-Za-z0-9_.\\-]` becomes `%XX`, absent
 * parts are `~`), so no value can forge another's key, not even through the rate limiter's own
 * key cleaning, and the key stays short enough for the cache.
 */
function rateLimitKey(Document $document, ?User $actor, bool $withSubject): string
{
    return GuardPipeline::rateLimitKeyOf(
        $document->getMorphClass(),
        'status',
        'go',
        $actor === null ? null : [$actor->getMorphClass(), (string) $actor->getKey()],
        $withSubject ? (string) $document->getKey() : null,
    );
}

function rateLimitedDocument(RateLimitScope $per): Document
{
    defineDocumentLifecycle(function (LifecycleBuilder $l) use ($per): void {
        baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->rateLimit(1, '1 hour', $per));
        $l->transition('back')->from('b')->to('a');
    });

    return Document::factory()->create();
}

it('builds the key from its escaped parts per scope', function (RateLimitScope $per, bool $withActor, bool $withSubject): void {
    $document = rateLimitedDocument($per);
    $user = User::factory()->create();

    Lifecycles::for($document)->by($user)->apply('go');

    expect(RateLimiter::attempts(rateLimitKey($document, $withActor ? $user : null, $withSubject)))->toBe(1)
        ->and(rateLimitKey($document, $user, true))->toBe('lifecycle:'.str_replace(['%', ':'], ['%25', '%3A'], 'RoundlyConsulting\\Lifecycle\\Tests\\Fixtures\\Models\\Document').':status:go:RoundlyConsulting\\Lifecycle\\Tests\\Fixtures\\Models\\User:'.$user->id.':'.$document->id);
})->with([
    'actor' => [RateLimitScope::Actor, true, false],
    'subject' => [RateLimitScope::Subject, false, true],
    'actor and subject' => [RateLimitScope::ActorAndSubject, true, true],
]);

it('escapes a hostile prefix instead of letting it forge parts', function (): void {
    config()->set('lifecycle.rate_limits.prefix', 'a:go:~');
    $document = rateLimitedDocument(RateLimitScope::Subject);

    $document->transition('go');

    expect(rateLimitKey($document, null, true))->toStartWith('a%3Ago%3A%7E:')
        ->and(RateLimiter::attempts(rateLimitKey($document, null, true)))->toBe(1);
});

it('never shares a counter between actors of different types with the same key', function (): void {
    $document = rateLimitedDocument(RateLimitScope::Actor);
    $user = User::factory()->create();

    Lifecycles::for($document)->by($user)->apply('go');
    $document->transition('back');

    expect($document->id)->toBe($user->id)
        ->and(Lifecycles::for($document)->by($user)->can('go'))->toBeFalse()
        ->and(Lifecycles::for($document)->by($document)->can('go'))->toBeTrue();
});

it('never shares a counter between keys the rate limiter would clean alike', function (): void {
    Relation::morphMap(['alice' => User::class, '&lice' => Document::class]);
    $document = rateLimitedDocument(RateLimitScope::Actor);
    $user = User::factory()->create();
    $other = Document::factory()->create();

    try {
        Lifecycles::for($document)->by($user)->apply('go');

        expect($user->getMorphClass())->toBe('alice')
            ->and($other->getMorphClass())->toBe('&lice')
            ->and($user->id)->toBe($document->id)
            ->and(Lifecycles::for($other)->by($document)->can('go'))->toBeTrue();
    } finally {
        Relation::morphMap([], false);
    }
});

it('limits actor-less calls of a per-actor limit per subject, not in one shared bucket', function (): void {
    $documents = [rateLimitedDocument(RateLimitScope::Actor), Document::factory()->create(), Document::factory()->create()];

    $codes = array_map(fn (Document $document): array => Lifecycles::for($document)->attempt('go')->decision->codes(), $documents);
    $documents[0]->transition('back');

    expect($codes)->toBe([[], [], []])
        ->and(Lifecycles::for($documents[0])->check('go')->codes())->toBe(['rate_limited']);
});

it('refuses loudly a rate-limit key the cache store could not hold', function (): void {
    Relation::morphMap([str_repeat('d', 240) => Document::class]);
    $document = rateLimitedDocument(RateLimitScope::Subject);

    try {
        expect(fn () => $document->transition('go'))->toThrow(InvalidLifecycleUsageException::class, 'rate-limit key')
            ->and($document->fresh()?->status)->toBe('a');
    } finally {
        Relation::morphMap([], false);
    }
});
