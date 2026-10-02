<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Enums\RateLimitScope;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

/**
 * Rate-limit keys are JSON-encoded parts — prefix, definition, transition, then the actor
 * and/or subject as [type, key] pairs — so no value can forge another's key.
 */
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

    $key = json_encode(['lifecycle', $class, 'go', $parts($user, $document)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

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

    $key = json_encode(['a","go",[["x', app(DefinitionRegistry::class)->of($document)->class, 'go', [[$document->getMorphClass(), $document->id]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

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
