<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * Why a transition or a rollback was refused. The values are part of the public contract —
 * hosts persist and switch on them — and are frozen from 1.0.0.
 */
enum DenialCode: string
{
    use Helpers;

    case UnknownTransition = 'unknown_transition';
    case NoTransitionToState = 'no_transition_to_state';
    case TerminalState = 'terminal_state';
    case NotFromCurrentState = 'not_from_current_state';
    case StaleVersion = 'stale_version';
    case Frozen = 'frozen';
    case Sealed = 'sealed';
    case SystemOnly = 'system_only';
    case SystemNotAllowed = 'system_not_allowed';
    case ActorRequired = 'actor_required';
    case ActorNotAllowed = 'actor_not_allowed';
    case Unauthorized = 'unauthorized';
    case ReasonRequired = 'reason_required';
    case ReasonTooLong = 'reason_too_long';
    case InvalidPayload = 'invalid_payload';
    case DeadlinePassed = 'deadline_passed';
    case NotYetAvailable = 'not_yet_available';
    case MinDwellNotReached = 'min_dwell_not_reached';
    case CooldownActive = 'cooldown_active';
    case MaxOccurrencesReached = 'max_occurrences_reached';
    case GuardFailed = 'guard_failed';
    case QuotaExceeded = 'quota_exceeded';
    case RateLimited = 'rate_limited';
    case NothingToRollback = 'nothing_to_rollback';
    case NotOnPath = 'not_on_path';
    case StateMismatch = 'state_mismatch';
    case Irreversible = 'irreversible';
    case NotReversible = 'not_reversible';
    case RollbackWindowPassed = 'rollback_window_passed';
    case RollbackConflict = 'rollback_conflict';

    /**
     * A retryable denial may clear on its own (time passes, a slot frees up); a scheduled
     * transition denied only by retryable codes is retried instead of failed.
     */
    public function isRetryable(): bool
    {
        return in_array($this, [
            self::NotYetAvailable,
            self::MinDwellNotReached,
            self::CooldownActive,
            self::QuotaExceeded,
            self::RateLimited,
            self::GuardFailed,
        ], true);
    }

    /**
     * Structural denials short-circuit the pipeline: nothing else is evaluated.
     */
    public function isStructural(): bool
    {
        return in_array($this, [
            self::UnknownTransition,
            self::NoTransitionToState,
            self::TerminalState,
            self::NotFromCurrentState,
        ], true);
    }
}
