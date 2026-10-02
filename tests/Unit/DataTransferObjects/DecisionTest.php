<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\Enums\DenialCode;

it('builds a translated denial from a code', function (): void {
    $at = CarbonImmutable::parse('2026-10-02 08:00:00', 'UTC');
    $denial = Denial::of(DenialCode::QuotaExceeded, ['max' => 5, 'current' => 5, 'state' => 'Active'], retryAfter: $at);

    expect($denial->code)->toBe('quota_exceeded')
        ->and($denial->message)->toBe('The limit of 5 for "Active" has been reached.')
        ->and($denial->is(DenialCode::QuotaExceeded))->toBeTrue()
        ->and($denial->is('quota_exceeded'))->toBeTrue()
        ->and($denial->denialCode())->toBe(DenialCode::QuotaExceeded)
        ->and($denial->isRetryable())->toBeTrue()
        ->and($denial->toArray())->toBe([
            'code' => 'quota_exceeded',
            'message' => 'The limit of 5 for "Active" has been reached.',
            'params' => ['max' => 5, 'current' => 5, 'state' => 'Active'],
            'retry_after' => '2026-10-02T08:00:00+00:00',
            'errors' => [],
        ]);
});

it('resolves custom codes through translations, then the generic text', function (): void {
    expect(Denial::of('no_photos')->message)->toBe('The transition is not allowed right now.')
        ->and(Denial::of('no_photos', message: 'Add a photo first.')->message)->toBe('Add a photo first.');

    app('translator')->addLines(['denials.no_photos' => 'Add at least :count photo.'], 'en', 'lifecycle');

    expect(Denial::of('no_photos', ['count' => 1])->message)->toBe('Add at least 1 photo.')
        ->and(Denial::of('flag', ['on' => true, 'off' => false])->params)->toBe(['on' => true, 'off' => false]);
});

it('translates denials in slovak', function (): void {
    app()->setLocale('sk');

    expect(Denial::of(DenialCode::Frozen)->message)->toBe('Tento záznam je zmrazený.');
});

it('treats a custom code as retryable only with a retry instant', function (): void {
    expect(Denial::of('custom')->isRetryable())->toBeFalse()
        ->and(Denial::of('custom', retryAfter: CarbonImmutable::now())->isRetryable())->toBeTrue()
        ->and(Denial::of(DenialCode::Frozen)->isRetryable())->toBeFalse();
});

it('allows', function (): void {
    $decision = Decision::allow();

    expect($decision->allowed)->toBeTrue()
        ->and($decision->denied())->toBeFalse()
        ->and($decision->first())->toBeNull()
        ->and($decision->isRetryable())->toBeFalse()
        ->and(Decision::from([])->allowed)->toBeTrue()
        ->and($decision->toArray())->toBe(['allowed' => true, 'retry_after' => null, 'denials' => []]);
});

it('collects denials and the latest retry instant when every denial is retryable', function (): void {
    $early = CarbonImmutable::parse('2026-10-02 08:00:00', 'UTC');
    $late = CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC');

    $decision = Decision::deny(
        Denial::of(DenialCode::CooldownActive, retryAfter: $late),
        Denial::of(DenialCode::RateLimited, retryAfter: $early),
        Denial::of(DenialCode::QuotaExceeded),
    );

    expect($decision->allowed)->toBeFalse()
        ->and($decision->retryAfter)->toEqual($late)
        ->and($decision->isRetryable())->toBeTrue()
        ->and($decision->has(DenialCode::RateLimited))->toBeTrue()
        ->and($decision->has('frozen'))->toBeFalse()
        ->and($decision->find('rate_limited')?->retryAfter)->toEqual($early)
        ->and($decision->first()?->code)->toBe('cooldown_active')
        ->and($decision->codes())->toBe(['cooldown_active', 'rate_limited', 'quota_exceeded'])
        ->and($decision->messages())->toHaveCount(3)
        ->and($decision->toArray()['retry_after'])->toBe('2026-10-02T09:00:00+00:00');
});

it('drops the retry instant when any denial is permanent', function (): void {
    $decision = Decision::from([
        Denial::of(DenialCode::CooldownActive, retryAfter: CarbonImmutable::now()),
        Denial::of(DenialCode::Unauthorized),
    ]);

    expect($decision->retryAfter)->toBeNull()
        ->and($decision->isRetryable())->toBeFalse();
});
