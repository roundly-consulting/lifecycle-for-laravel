<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * What the definition validator reports. Errors stop a definition from compiling; warnings
 * are reported by `lifecycle:validate` only.
 */
enum IssueCode: string
{
    use Helpers;

    case NoStates = 'no_states';
    case DuplicateState = 'duplicate_state';
    case InvalidStateValue = 'invalid_state_value';
    case MixedStateTypes = 'mixed_state_types';
    case MissingInitial = 'missing_initial';
    case UnknownState = 'unknown_state';
    case InitialIsTerminal = 'initial_is_terminal';
    case InvalidTransitionName = 'invalid_transition_name';
    case ReservedTransitionName = 'reserved_transition_name';
    case DuplicateTransition = 'duplicate_transition';
    case TransitionWithoutSource = 'transition_without_source';
    case TransitionWithoutTarget = 'transition_without_target';
    case TerminalHasOutgoing = 'terminal_has_outgoing';
    case SelfTransitionNotAllowed = 'self_transition_not_allowed';
    case TtlOnTerminal = 'ttl_on_terminal';
    case TtlWithoutExpiryTransition = 'ttl_without_expiry_transition';
    case ExpiryTransitionInvalid = 'expiry_transition_invalid';
    case ExpiryTransitionNotSystem = 'expiry_transition_not_system';
    case GraceWithoutTtl = 'grace_without_ttl';
    case WarnWithoutTtl = 'warn_without_ttl';
    case QuotaOnInitialState = 'quota_on_initial_state';
    case InvalidQuota = 'invalid_quota';
    case DuplicateQuotaName = 'duplicate_quota_name';
    case InvalidDuration = 'invalid_duration';
    case InvalidRateLimit = 'invalid_rate_limit';
    case InvalidMaxOccurrences = 'invalid_max_occurrences';
    case InvalidWarningLeads = 'invalid_warning_leads';
    case ExpiryBlockedBySeal = 'expiry_blocked_by_seal';
    case UninstantiableDefinition = 'uninstantiable_definition';
    case InvalidIdentifier = 'invalid_identifier';
    case ConflictingActorRules = 'conflicting_actor_rules';

    case UnreachableState = 'unreachable_state';
    case DeadEndState = 'dead_end_state';
    case AmbiguousTarget = 'ambiguous_target';
    case IrreversibleByHandler = 'irreversible_by_handler';
    case SealedTerminal = 'sealed_terminal';

    public function severity(): IssueSeverity
    {
        return match ($this) {
            self::UnreachableState,
            self::DeadEndState,
            self::AmbiguousTarget,
            self::IrreversibleByHandler,
            self::SealedTerminal => IssueSeverity::Warning,
            default => IssueSeverity::Error,
        };
    }
}
