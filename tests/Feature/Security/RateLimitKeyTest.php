<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\RateLimiter;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Enums\RateLimitScope;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

/**
 * Rate-limit keys URL-encode JSON-encoded parts — prefix, definition, transition, then the actor
 * and/or subject as [type, key] pairs — so no value can forge another's key, even through the
 * rate limiter's own key cleaning.
 */
function rateLimitKey(array $parts): string
{
    return rawurlencode(json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}
function rateLimitedDocument(RateLimitScope $per): Document
{
    defineDocumentLifecycle(function (LifecycleBuilder $l) use ($per): void {
        baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->rateLimit(1, '1 hour', $per));
        $l->transition('back')->from('b')->to('a');
    });

    return Document::factory()->create();
}

it('builds the key from JSON-encoded parts per scope', function (RateLimitScope $per, Closure $parts): void {
    $document = rateLimitedDocument($per);
    $user = User::factory()->create();
    $class = app(DefinitionRegistry::class)->of($document)->class;

    Lifecycles::for($document)->by($user)->apply('go');

    $key = rateLimitKey(['lifecycle', $class, 'go', $parts($user, $document)]);

    expect(RateLimiter::attempts($key))->toBe(1);
})->with([
    'actor' => [RateLimitScope::Actor, fn (User $u, Document $d): array => [[$u->getMorphClass(), $u->id]]],
    'subject' => [RateLimitScope::Subject, fn (User $u, Document $d): array => [[$d->getMorphClass(), $d->id]]],
    'actor and subject' => [RateLimitScope::ActorAndSubject, fn (User $u, Document $d): array => [[$u->getMorphClass(), $u->id], [$d->getMorphClass(), $d->id]]],
]);

it('keeps a hostile prefix inside its JSON string', function (): void {
    config()->set('lifecycle.rate_limits.prefix', 'a","go",[["x');
    $document = rateLimitedDocument(RateLimitScope::Subject);

    $document->transition('go');

    $key = rateLimitKey(['a","go",[["x', app(DefinitionRegistry::class)->of($document)->class, 'go', [[$document->getMorphClass(), $document->id]]]);

    expect(RateLimiter::attempts($key))->toBe(1);
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
