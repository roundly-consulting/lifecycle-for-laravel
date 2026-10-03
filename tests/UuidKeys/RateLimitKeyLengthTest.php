<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Enums\RateLimitScope;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\UuidDocument;

/**
 * A rate-limit key of a long namespaced subject with a uuid key, limited per actor and subject,
 * still fits the strictest common cache key limit (memcached's 250 bytes, the `database` store's
 * 255-character column) once the store prefix and the limiter's `:timer` suffix are added.
 */
it('keeps the stored rate-limit keys of a namespaced uuid subject under 250 characters', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go
        ->rateLimit(5, '1 hour', RateLimitScope::ActorAndSubject)));
    $limiter = new class(app('cache')->store()) extends RateLimiter
    {
        /** @var list<string> */
        public array $keys = [];

        public function hit($key, $decaySeconds = 60)
        {
            $this->keys[] = $this->cleanRateLimiterKey($key);

            return parent::hit($key, $decaySeconds);
        }
    };
    app()->instance(RateLimiter::class, $limiter);
    $document = UuidDocument::query()->create();

    Lifecycles::for($document)->by(User::factory()->create())->apply('go');

    $prefix = app('cache')->store()->getStore()->getPrefix();
    $stored = array_map(fn (string $key): int => strlen($prefix.$key.':timer'), $limiter->keys);

    expect($limiter->keys)->toHaveCount(1)
        ->and(strlen(UuidDocument::class))->toBeGreaterThan(60)
        ->and(strlen((string) $document->getKey()))->toBe(36)
        ->and($stored[0])->toBeLessThanOrEqual(250);
});
