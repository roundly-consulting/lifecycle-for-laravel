<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Enums\DenialCode;
use RoundlyConsulting\Lifecycle\Enums\IssueCode;
use RoundlyConsulting\Lifecycle\Enums\IssueSeverity;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;

/**
 * Hosts persist and switch on these values; renaming one after 1.0.0 is a breaking change.
 */
it('freezes the denial codes', function (): void {
    expect(DenialCode::values()->all())->toBe([
        'unknown_transition', 'no_transition_to_state', 'terminal_state', 'not_from_current_state',
        'stale_version', 'frozen', 'sealed', 'system_only', 'system_not_allowed', 'actor_required',
        'actor_not_allowed', 'unauthorized', 'reason_required', 'reason_too_long', 'invalid_payload',
        'deadline_passed', 'not_yet_available', 'min_dwell_not_reached', 'cooldown_active',
        'max_occurrences_reached', 'guard_failed', 'quota_exceeded', 'rate_limited',
        'nothing_to_rollback', 'not_on_path', 'state_mismatch', 'irreversible', 'not_reversible',
        'rollback_window_passed', 'rollback_conflict',
    ]);
});

it('freezes the history kinds', function (): void {
    expect(TransitionKind::values()->all())->toBe(['initial', 'transition', 'expiry', 'scheduled', 'rollback', 'adopted']);
});

it('classifies denial codes', function (): void {
    $retryable = array_values(array_filter(DenialCode::cases(), fn (DenialCode $code): bool => $code->isRetryable()));
    $structural = array_values(array_filter(DenialCode::cases(), fn (DenialCode $code): bool => $code->isStructural()));

    expect($retryable)->toBe([
        DenialCode::NotYetAvailable, DenialCode::MinDwellNotReached, DenialCode::CooldownActive,
        DenialCode::GuardFailed, DenialCode::QuotaExceeded, DenialCode::RateLimited,
    ])->and($structural)->toBe([
        DenialCode::UnknownTransition, DenialCode::NoTransitionToState, DenialCode::TerminalState, DenialCode::NotFromCurrentState,
    ]);
});

it('classifies history kinds and schedule statuses', function (): void {
    expect(array_map(fn (TransitionKind $k): bool => $k->isOnEffectivePath(), TransitionKind::cases()))
        ->toBe([true, true, true, true, false, true])
        ->and(array_map(fn (TransitionKind $k): bool => $k->isReversibleKind(), TransitionKind::cases()))
        ->toBe([false, true, false, true, false, false])
        ->and(array_map(fn (ScheduleStatus $s): bool => $s->isOpen(), ScheduleStatus::cases()))
        ->toBe([true, true, false, false, false]);
});

it('splits issue codes into errors and warnings', function (): void {
    $warnings = array_values(array_filter(IssueCode::cases(), fn (IssueCode $code): bool => $code->severity() === IssueSeverity::Warning));

    expect($warnings)->toBe([
        IssueCode::UnreachableState, IssueCode::DeadEndState, IssueCode::AmbiguousTarget,
        IssueCode::IrreversibleByHandler, IssueCode::SealedTerminal,
    ]);
});
