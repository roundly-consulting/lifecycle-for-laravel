<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Enums\IssueCode;
use RoundlyConsulting\Lifecycle\Enums\IssueSeverity;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleDefinitionException;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\Invalid\BrokenLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\Invalid\RequiresArgumentsDefinition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\Invalid\WarningsOnlyLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\EmptyStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\OtherStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Handlers\CompensatingHandler;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Handlers\PlainHandler;

/**
 * Every IssueCode has a definition that triggers it. Errors stop compilation; warnings never do.
 */
/**
 * @return array<string, array{IssueCode, Closure}>
 */
function issueFixtures(): array
{
    return [
        'no_states' => [IssueCode::NoStates, fn (LifecycleBuilder $l) => $l->initial('a')],
        'no_states (with transitions)' => [IssueCode::NoStates, fn (LifecycleBuilder $l) => $l->initial('a')->transition('x')->from('a')->to('b')],
        'no_states (empty list)' => [IssueCode::NoStates, fn (LifecycleBuilder $l) => $l->states([])->initial('a')],
        'no_states (empty enum)' => [IssueCode::NoStates, fn (LifecycleBuilder $l) => $l->states(EmptyStatus::class)->initial('a')],
        'duplicate_state' => [IssueCode::DuplicateState, fn (LifecycleBuilder $l) => $l->states(['a', 'a', 'b'])->initial('a')],
        'invalid_state_value (empty)' => [IssueCode::InvalidStateValue, fn (LifecycleBuilder $l) => $l->states(['', 'a'])->initial('a')],
        'invalid_state_value (too long)' => [IssueCode::InvalidStateValue, fn (LifecycleBuilder $l) => $l->states([str_repeat('x', 65), 'a'])->initial('a')],
        'invalid_state_value (not an enum)' => [IssueCode::InvalidStateValue, fn (LifecycleBuilder $l) => $l->states(stdClass::class)->initial('a')],
        'invalid_state_value (type)' => [IssueCode::InvalidStateValue, fn (LifecycleBuilder $l) => $l->states([1.5, 'a'])->initial('a')],
        'mixed_state_types (enum + string)' => [IssueCode::MixedStateTypes, fn (LifecycleBuilder $l) => $l->states([ListingStatus::Draft, 'x'])->initial(ListingStatus::Draft)],
        'mixed_state_types (string + enum)' => [IssueCode::MixedStateTypes, fn (LifecycleBuilder $l) => $l->states(['x', ListingStatus::Draft])->initial('x')],
        'mixed_state_types (two enums)' => [IssueCode::MixedStateTypes, fn (LifecycleBuilder $l) => $l->states([ListingStatus::Draft, OtherStatus::Draft])->initial(ListingStatus::Draft)],
        'missing_initial' => [IssueCode::MissingInitial, fn (LifecycleBuilder $l) => $l->states(['a'])],
        'unknown_state (initial)' => [IssueCode::UnknownState, fn (LifecycleBuilder $l) => $l->states(['a'])->initial('z')],
        'unknown_state (enum of another class)' => [IssueCode::UnknownState, fn (LifecycleBuilder $l) => $l->states(ListingStatus::class)->initial(OtherStatus::Draft)],
        'unknown_state (terminal)' => [IssueCode::UnknownState, fn (LifecycleBuilder $l) => baseLifecycle($l)->terminal('z')],
        'unknown_state (state settings)' => [IssueCode::UnknownState, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('z')->label('Z')],
        'unknown_state (target)' => [IssueCode::UnknownState, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('a')->to('z')],
        'unknown_state (source)' => [IssueCode::UnknownState, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('z')->to('b')],
        'unknown_state (excluded)' => [IssueCode::UnknownState, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->fromAnyExcept('z')->to('c')],
        'initial_is_terminal' => [IssueCode::InitialIsTerminal, fn (LifecycleBuilder $l) => baseLifecycle($l)->terminal('a')],
        'invalid_transition_name' => [IssueCode::InvalidTransitionName, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('has space')->from('a')->to('b')],
        'reserved_transition_name' => [IssueCode::ReservedTransitionName, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('@expiry')->from('a')->to('b')],
        'duplicate_transition' => [IssueCode::DuplicateTransition, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('go')->from('b')->to('a')],
        'transition_without_source' => [IssueCode::TransitionWithoutSource, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->to('b')],
        'transition_without_source (empty wildcard)' => [IssueCode::TransitionWithoutSource, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->fromAnyExcept('a', 'b')->to('c')],
        'transition_without_target' => [IssueCode::TransitionWithoutTarget, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('a')],
        'terminal_has_outgoing' => [IssueCode::TerminalHasOutgoing, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('c')->to('a')],
        'self_transition_not_allowed' => [IssueCode::SelfTransitionNotAllowed, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('b')->to('b')],
        'ttl_on_terminal' => [IssueCode::TtlOnTerminal, function (LifecycleBuilder $l): void {
            baseLifecycle($l)->state('c')->ttl('1 day')->expiresVia('finish');
        }],
        'ttl_without_expiry_transition' => [IssueCode::TtlWithoutExpiryTransition, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->ttl('1 day')],
        'ttl_without_expiry_transition (attribute)' => [IssueCode::TtlWithoutExpiryTransition, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->expiresAtAttribute('expires_at')],
        'ttl_without_expiry_transition (closure)' => [IssueCode::TtlWithoutExpiryTransition, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->ttl(fn () => null)],
        'expiry_transition_invalid (unknown)' => [IssueCode::ExpiryTransitionInvalid, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->ttl('1 day')->expiresVia('nope')],
        'expiry_transition_invalid (wrong source)' => [IssueCode::ExpiryTransitionInvalid, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->ttl('1 day')->expiresVia('go')],
        'expiry_transition_not_system' => [IssueCode::ExpiryTransitionNotSystem, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->ttl('1 day')->expiresVia('finish')],
        'grace_without_ttl' => [IssueCode::GraceWithoutTtl, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->grace('1 day')],
        'warn_without_ttl' => [IssueCode::WarnWithoutTtl, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->warnBefore('1 day')],
        'quota_on_initial_state' => [IssueCode::QuotaOnInitialState, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('a')->quota(5)],
        'invalid_quota (negative)' => [IssueCode::InvalidQuota, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->quota(-1)],
        'invalid_quota (name)' => [IssueCode::InvalidQuota, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->quota(1, name: str_repeat('q', 65))],
        'duplicate_quota_name' => [IssueCode::DuplicateQuotaName, function (LifecycleBuilder $l): void {
            baseLifecycle($l)->state('b')->quota(1, 'user_id')->quota(2, 'user_id');
        }],
        'invalid_duration (ttl)' => [IssueCode::InvalidDuration, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->ttl('soon')],
        'invalid_duration (zero)' => [IssueCode::InvalidDuration, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->minDwell('0 days')],
        'invalid_duration (negative)' => [IssueCode::InvalidDuration, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->sealedAfter('-1 day')],
        'invalid_duration (grace)' => [IssueCode::InvalidDuration, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->grace('x')],
        'invalid_duration (lead)' => [IssueCode::InvalidDuration, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->warnBefore('x')],
        'invalid_duration (cooldown)' => [IssueCode::InvalidDuration, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('a')->to('c')->cooldown('later')],
        'invalid_duration (window)' => [IssueCode::InvalidDuration, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('a')->to('c')->reversible('never')],
        'invalid_duration (rate limit)' => [IssueCode::InvalidDuration, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('a')->to('c')->rateLimit(3, 'often')],
        'invalid_duration (offset)' => [IssueCode::InvalidDuration, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('a')->to('c')->notBefore('starts_at', 'whenever')],
        'invalid_rate_limit' => [IssueCode::InvalidRateLimit, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('a')->to('c')->rateLimit(0, '1 hour')],
        'invalid_max_occurrences' => [IssueCode::InvalidMaxOccurrences, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('a')->to('c')->maxOccurrences(0)],
        'invalid_warning_leads (duplicates)' => [IssueCode::InvalidWarningLeads, function (LifecycleBuilder $l): void {
            baseLifecycle($l)->transition('lapse')->from('b')->to('c')->systemOnly();
            $l->state('b')->ttl('30 days')->expiresVia('lapse')->warnBefore('1 day', '24 hours');
        }],
        'invalid_warning_leads (too many)' => [IssueCode::InvalidWarningLeads, function (LifecycleBuilder $l): void {
            baseLifecycle($l)->transition('lapse')->from('b')->to('c')->systemOnly();
            $l->state('b')->ttl('30 days')->expiresVia('lapse')->warnBefore(...array_map(fn (int $h): string => $h.' hours', range(1, 17)));
        }],
        'expiry_blocked_by_seal' => [IssueCode::ExpiryBlockedBySeal, function (LifecycleBuilder $l): void {
            baseLifecycle($l)->transition('lapse')->from('b')->to('c')->systemOnly();
            $l->state('b')->ttl('30 days')->expiresVia('lapse')->sealedAfter('1 day');
        }],
        'invalid_identifier (stamp)' => [IssueCode::InvalidIdentifier, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->stamps('paid at')],
        'invalid_identifier (expiry attribute)' => [IssueCode::InvalidIdentifier, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->expiresAtAttribute('1st')],
        'invalid_identifier (quota scope)' => [IssueCode::InvalidIdentifier, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->quota(3, 'user_id; drop table x')],
        'invalid_identifier (snapshot)' => [IssueCode::InvalidIdentifier, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('a')->to('c')->snapshots('price-1')],
        'invalid_identifier (deadline)' => [IssueCode::InvalidIdentifier, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('a')->to('c')->notAfter('ends at')],
        'conflicting_actor_rules (ability)' => [IssueCode::ConflictingActorRules, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('a')->to('c')->systemOnly()->ability('close')],
        'conflicting_actor_rules (reason)' => [IssueCode::ConflictingActorRules, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('a')->to('c')->systemOnly()->requiresReason()],
        'unreachable_state' => [IssueCode::UnreachableState, fn (LifecycleBuilder $l) => $l->states(['a', 'b', 'island'])->initial('a')->terminal('b')->transition('go')->from('a')->to('b')],
        'dead_end_state' => [IssueCode::DeadEndState, fn (LifecycleBuilder $l) => $l->states(['a', 'stuck'])->initial('a')->transition('go')->from('a')->to('stuck')],
        'ambiguous_target' => [IssueCode::AmbiguousTarget, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('also_go')->from('a')->to('b')],
        'irreversible_by_handler' => [IssueCode::IrreversibleByHandler, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('a')->to('c')->handledBy(PlainHandler::class)],
        'irreversible_by_handler (closure)' => [IssueCode::IrreversibleByHandler, fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('a')->to('c')->handledBy(fn () => null)],
        'sealed_terminal' => [IssueCode::SealedTerminal, fn (LifecycleBuilder $l) => baseLifecycle($l)->state('c')->sealedAfter('1 day')],
    ];
}

dataset('issues', issueFixtures());

it('reports the issue', function (IssueCode $code, Closure $define): void {
    $report = validateLifecycle($define);

    expect($report->has($code))->toBeTrue(implode(', ', array_map(fn ($i) => $i->code->value, $report->issues)))
        ->and($report->definition)->toBe('Inline');
})->with('issues');

it('stops compilation on errors only', function (IssueCode $code, Closure $define): void {
    if ($code->severity() === IssueSeverity::Error) {
        expect(fn () => compileLifecycle($define))->toThrow(InvalidLifecycleDefinitionException::class);

        return;
    }

    expect(compileLifecycle($define)->class)->toBe('Inline');
})->with('issues');

it('has a triggering fixture for every issue code', function (): void {
    $triggered = array_map(fn (array $fixture): string => $fixture[0]->value, issueFixtures());
    $triggered[] = IssueCode::UninstantiableDefinition->value; // needs a class, tested below

    expect(array_values(array_diff(IssueCode::values()->all(), $triggered)))->toBe([]);
});

it('accepts the minimal base lifecycle without any issue', function (): void {
    expect(validateLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l))->issues)->toBe([]);
});

it('treats a compensating handler or an explicit opt-in as reversible', function (): void {
    $report = validateLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l);
        $l->transition('x')->from('a')->to('c')->handledBy(CompensatingHandler::class);
        $l->transition('y')->from('a')->to('c')->handledBy(new CompensatingHandler);
        $l->transition('z')->from('a')->to('c')->handledBy(PlainHandler::class)->reversible(withoutCompensation: true);
        $l->transition('w')->from('a')->to('c')->handledBy(PlainHandler::class)->irreversible();
    });

    expect($report->has(IssueCode::IrreversibleByHandler))->toBeFalse();
});

it('reports a sealed expiring state as fine when its expiry ignores the seal', function (): void {
    $report = validateLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->transition('lapse')->from('b')->to('c')->systemOnly()->ignoresSeal();
        $l->state('b')->ttl('30 days')->expiresVia('lapse')->sealedAfter('1 day')->grace('1 day')->warnBefore('2 days', '1 day');
    });

    expect($report->errors())->toBe([]);
});

it('reports an uninstantiable definition and refuses to compile it', function (): void {
    $registry = app(DefinitionRegistry::class);

    expect($registry->validate(RequiresArgumentsDefinition::class)->has(IssueCode::UninstantiableDefinition))->toBeTrue()
        ->and(fn () => $registry->get(RequiresArgumentsDefinition::class))
        ->toThrow(InvalidLifecycleDefinitionException::class, 'uninstantiable_definition');
});

it('lists every error with its path and a translated message', function (): void {
    try {
        app(DefinitionRegistry::class)->get(BrokenLifecycle::class);
        $this->fail('expected an exception');
    } catch (InvalidLifecycleDefinitionException $exception) {
        expect($exception->getMessage())
            ->toContain(BrokenLifecycle::class)
            ->toContain('missing_initial (initial): No initial state is declared.')
            ->toContain('unknown_state (transition:go): The state "z" is not declared.')
            ->and($exception->report()->errors())->toHaveCount(2)
            ->and($exception->report()->isValid())->toBeFalse();
    }
});

it('compiles a definition that only has warnings', function (): void {
    $registry = app(DefinitionRegistry::class);
    $report = $registry->validate(WarningsOnlyLifecycle::class);

    expect($report->isValid())->toBeTrue()
        ->and(array_map(fn ($issue) => $issue->code, $report->warnings()))->toBe([IssueCode::UnreachableState, IssueCode::DeadEndState])
        ->and($registry->get(WarningsOnlyLifecycle::class)->initial)->toBe('start');
});

it('translates issue messages in slovak', function (): void {
    app()->setLocale('sk');

    expect(validateLifecycle(fn (LifecycleBuilder $l) => $l->states(['a']))->issues[0]->message())
        ->toBe('Nie je deklarovaný počiatočný stav.');
});
