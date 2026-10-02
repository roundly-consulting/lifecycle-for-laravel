<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\Enums\DenialCode;
use RoundlyConsulting\Lifecycle\Exceptions\ConcurrentTransitionException;
use RoundlyConsulting\Lifecycle\Exceptions\LifecycleException;
use RoundlyConsulting\Lifecycle\Exceptions\RollbackDeniedException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\PackageToolkit\Contracts\HasRetryAfter;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

it('turns a transition denial into a 422 with payload errors under their own keys', function (): void {
    $exception = TransitionDeniedException::because(Decision::from([
        Denial::of(DenialCode::Frozen, ['transition' => 'Go', 'state' => 'A']),
        Denial::of(DenialCode::InvalidPayload, [], errors: ['note' => ['Too long!']]),
    ]));

    expect($exception->toValidationException('action')->errors())->toBe([
        'action' => ['This record is frozen.'],
        'note' => ['Too long!'],
    ]);
});

it('carries the decision, the denials and the retry instant of a refused rollback', function (): void {
    $denial = Denial::of(DenialCode::QuotaExceeded, ['max' => 1, 'current' => 1, 'transition' => 'Go', 'state' => 'B'], retryAfter: CarbonImmutable::parse('2026-10-02 10:05:00', 'UTC'));
    $exception = RollbackDeniedException::because(Decision::from([$denial]));

    expect($exception)->toBeInstanceOf(LifecycleException::class)->toBeInstanceOf(HasRetryAfter::class)
        ->and($exception->decision()->codes())->toBe(['quota_exceeded'])
        ->and($exception->denials())->toBe([$denial])
        ->and($exception->retryAfterSeconds())->toBe(300)
        ->and($exception->getMessage())->toBe($denial->message)
        ->and($exception->toValidationException()->errors())->toBe(['rollback' => [$denial->message]])
        ->and(RollbackDeniedException::because(Decision::from([]))->getMessage())->toBe('The rollback is not allowed.');
});

it('names the subject or schedule that changed concurrently', function (): void {
    $document = Document::factory()->create();

    expect(ConcurrentTransitionException::lostRace($document, 'status')->getMessage())
        ->toBe('The lifecycle [status] of ['.Document::class.' #1] was changed concurrently.')
        ->and(ConcurrentTransitionException::scheduleChanged(7)->getMessage())->toBe('The lifecycle schedule #7 was changed concurrently.');
});
