<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition;

use BackedEnum;
use RoundlyConsulting\Lifecycle\Contracts\CompensatesTransition;
use RoundlyConsulting\Lifecycle\Enums\IssueCode;
use RoundlyConsulting\Lifecycle\Support\Identifiers;

/**
 * Finds every error and warning in a populated builder without throwing. Errors keep the
 * definition from compiling; warnings only show up in `lifecycle:validate`.
 *
 * @internal
 */
final class DefinitionValidator
{
    /** @var list<Issue> */
    private array $issues = [];

    public function validate(string $definition, LifecycleBuilder $builder): ValidationReport
    {
        $this->issues = [];

        $declared = DeclaredStates::from($builder->stateDeclaration);
        array_push($this->issues, ...$declared->issues);

        $terminal = $this->terminal($builder, $declared);
        $initial = $this->initial($builder, $declared, $terminal);

        /** @var array<string, TransitionBuilder> $transitions first declaration of each name */
        $transitions = [];
        /** @var array<string, list<string>> $sources */
        $sources = [];

        foreach ($builder->transitionBuilders as $transition) {
            $valid = $this->transition($transition, $declared, $terminal, array_key_exists('t'.$transition->name, $transitions));

            if (! array_key_exists('t'.$transition->name, $transitions)) {
                $transitions['t'.$transition->name] = $transition;
            }

            if ($valid) {
                $sources['t'.$transition->name] = Compiler::sources($transition, $declared, $terminal);
            }
        }

        $this->states($builder, $declared, $terminal, $initial, $transitions, $sources);

        if ($initial !== null) {
            $this->graphWarnings($declared, $terminal, $initial, $transitions, $sources);
        }

        return new ValidationReport($definition, $this->issues);
    }

    /**
     * @return list<string>
     */
    private function terminal(LifecycleBuilder $builder, DeclaredStates $declared): array
    {
        foreach ($builder->terminal as $state) {
            if ($declared->values !== [] && $declared->resolve($state) === null) {
                $this->issue(IssueCode::UnknownState, 'terminal', ['state' => $this->describe($state)]);
            }
        }

        return Compiler::terminalKeys($builder, $declared);
    }

    /**
     * @param  list<string>  $terminal
     */
    private function initial(LifecycleBuilder $builder, DeclaredStates $declared, array $terminal): ?string
    {
        if ($builder->initial === null) {
            $this->issue(IssueCode::MissingInitial, 'initial');

            return null;
        }

        if ($declared->values === []) {
            return null;
        }

        $initial = $declared->resolve($builder->initial);

        if ($initial === null) {
            $this->issue(IssueCode::UnknownState, 'initial', ['state' => $this->describe($builder->initial)]);

            return null;
        }

        if (in_array($initial, $terminal, true)) {
            $this->issue(IssueCode::InitialIsTerminal, 'initial', ['state' => $initial]);
        }

        return $initial;
    }

    /**
     * @param  list<string>  $terminal
     */
    private function transition(TransitionBuilder $transition, DeclaredStates $declared, array $terminal, bool $duplicate): bool
    {
        $path = 'transition:'.$transition->name;
        $valid = true;

        if (str_starts_with($transition->name, '@')) {
            $this->issue(IssueCode::ReservedTransitionName, $path, ['transition' => $transition->name]);
            $valid = false;
        } elseif (! Identifiers::isTransitionName($transition->name)) {
            $this->issue(IssueCode::InvalidTransitionName, $path, ['transition' => $transition->name]);
            $valid = false;
        }

        if ($duplicate) {
            $this->issue(IssueCode::DuplicateTransition, $path, ['transition' => $transition->name]);
            $valid = false;
        }

        array_push($this->issues, ...$transition->issues);

        if ($transition->systemOnly && ($transition->hasActorRules() || $transition->requiresReason)) {
            $this->issue(IssueCode::ConflictingActorRules, $path, ['transition' => $transition->name]);
        }

        if ($transition->handler !== null && ! $transition->irreversible && ! $transition->withoutCompensation && ! $this->compensates($transition)) {
            $this->issue(IssueCode::IrreversibleByHandler, $path, ['transition' => $transition->name]);
        }

        if ($declared->values === []) {
            return false;
        }

        $to = null;

        if ($transition->to === null) {
            $this->issue(IssueCode::TransitionWithoutTarget, $path, ['transition' => $transition->name]);
            $valid = false;
        } else {
            $to = $declared->resolve($transition->to);

            if ($to === null) {
                $this->issue(IssueCode::UnknownState, $path, ['state' => $this->describe($transition->to)]);
                $valid = false;
            }
        }

        foreach ([...$transition->from, ...$transition->except] as $state) {
            if ($declared->resolve($state) === null) {
                $this->issue(IssueCode::UnknownState, $path, ['state' => $this->describe($state)]);
                $valid = false;
            }
        }

        foreach ($transition->from as $state) {
            $key = $declared->resolve($state);

            if ($key !== null && in_array($key, $terminal, true)) {
                $this->issue(IssueCode::TerminalHasOutgoing, $path, ['state' => $key]);
                $valid = false;
            }

            if ($key !== null && $key === $to && ! $transition->allowSelf) {
                $this->issue(IssueCode::SelfTransitionNotAllowed, $path, ['state' => $key]);
                $valid = false;
            }
        }

        if ($to !== null && ($transition->from !== [] || $transition->wildcard) && Compiler::sources($transition, $declared, $terminal) === []) {
            $this->issue(IssueCode::TransitionWithoutSource, $path, ['transition' => $transition->name]);
            $valid = false;
        } elseif ($transition->from === [] && ! $transition->wildcard) {
            $this->issue(IssueCode::TransitionWithoutSource, $path, ['transition' => $transition->name]);
            $valid = false;
        }

        return $valid;
    }

    /**
     * @param  list<string>  $terminal
     * @param  array<string, TransitionBuilder>  $transitions
     * @param  array<string, list<string>>  $sources
     */
    private function states(LifecycleBuilder $builder, DeclaredStates $declared, array $terminal, ?string $initial, array $transitions, array $sources): void
    {
        $quotaNames = [];

        foreach ($builder->stateBuilders as $state) {
            $path = 'state:'.$state->key;
            array_push($this->issues, ...$state->issues);

            if ($declared->values !== [] && $declared->resolve($state->state) === null) {
                $this->issue(IssueCode::UnknownState, $path, ['state' => $state->key]);

                continue;
            }

            $isTerminal = in_array($state->key, $terminal, true);

            if ($state->hasTtl()) {
                $this->expiry($state, $isTerminal, $transitions, $sources);
            } else {
                if ($state->graceDeclared) {
                    $this->issue(IssueCode::GraceWithoutTtl, $path, ['state' => $state->key]);
                }

                if ($state->leadsDeclared) {
                    $this->issue(IssueCode::WarnWithoutTtl, $path, ['state' => $state->key]);
                }
            }

            if ($state->leadsDeclared && ($state->declaredLeadCount > 16 || $this->hasDuplicateLeads($state))) {
                $this->issue(IssueCode::InvalidWarningLeads, $path, ['state' => $state->key]);
            }

            if ($isTerminal && $state->sealedAfter !== null) {
                $this->issue(IssueCode::SealedTerminal, $path, ['state' => $state->key]);
            }

            foreach ($state->quotas as $quota) {
                if ($state->key === $initial) {
                    $this->issue(IssueCode::QuotaOnInitialState, $path, ['state' => $state->key]);
                }

                if ((is_int($quota->max) && $quota->max < 0) || $quota->name === '' || mb_strlen($quota->name) > 64) {
                    $this->issue(IssueCode::InvalidQuota, $path, ['quota' => $quota->name]);
                }

                if (in_array($quota->name, $quotaNames, true)) {
                    $this->issue(IssueCode::DuplicateQuotaName, $path, ['quota' => $quota->name]);
                }

                $quotaNames[] = $quota->name;
            }
        }
    }

    /**
     * @param  array<string, TransitionBuilder>  $transitions
     * @param  array<string, list<string>>  $sources
     */
    private function expiry(StateBuilder $state, bool $isTerminal, array $transitions, array $sources): void
    {
        $path = 'state:'.$state->key;

        if ($isTerminal) {
            $this->issue(IssueCode::TtlOnTerminal, $path, ['state' => $state->key]);
        }

        if ($state->expiresVia === null) {
            $this->issue(IssueCode::TtlWithoutExpiryTransition, $path, ['state' => $state->key]);

            return;
        }

        $transition = $transitions['t'.$state->expiresVia] ?? null;

        if ($transition === null || ! in_array($state->key, $sources['t'.$state->expiresVia] ?? [], true)) {
            $this->issue(IssueCode::ExpiryTransitionInvalid, $path, ['state' => $state->key, 'transition' => $state->expiresVia]);

            return;
        }

        if (! $transition->systemOnly && ! $transition->allowSystem) {
            $this->issue(IssueCode::ExpiryTransitionNotSystem, $path, ['state' => $state->key, 'transition' => $state->expiresVia]);
        }

        if ($state->sealedAfter !== null && ! $transition->ignoresSeal) {
            $this->issue(IssueCode::ExpiryBlockedBySeal, $path, ['state' => $state->key, 'transition' => $state->expiresVia]);
        }
    }

    /**
     * @param  list<string>  $terminal
     * @param  array<string, TransitionBuilder>  $transitions
     * @param  array<string, list<string>>  $sources
     */
    private function graphWarnings(DeclaredStates $declared, array $terminal, string $initial, array $transitions, array $sources): void
    {
        $edges = [];

        foreach ($transitions as $index => $transition) {
            $to = $transition->to === null ? null : $declared->resolve($transition->to);

            if ($to === null || ! array_key_exists($index, $sources)) {
                continue;
            }

            foreach ($sources[$index] as $from) {
                $edges[] = [$from, $to, $transition->name];
            }
        }

        $reached = [$initial];
        $queue = [$initial];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($edges as [$from, $to]) {
                if ($from === $current && ! in_array($to, $reached, true)) {
                    $reached[] = $to;
                    $queue[] = $to;
                }
            }
        }

        foreach ($declared->keys() as $key) {
            if (! in_array($key, $reached, true)) {
                $this->issue(IssueCode::UnreachableState, 'state:'.$key, ['state' => $key]);
            }

            $leaves = array_filter($edges, static fn (array $edge): bool => $edge[0] === $key);

            if ($leaves === [] && ! in_array($key, $terminal, true)) {
                $this->issue(IssueCode::DeadEndState, 'state:'.$key, ['state' => $key]);
            }
        }

        $seen = [];

        foreach ($edges as [$from, $to, $name]) {
            $pair = $from."\0".$to;

            if (array_key_exists($pair, $seen) && $seen[$pair] !== $name) {
                $this->issue(IssueCode::AmbiguousTarget, 'state:'.$from, ['state' => $from, 'target' => $to, 'transition' => $name]);
            }

            $seen[$pair] ??= $name;
        }
    }

    private function hasDuplicateLeads(StateBuilder $state): bool
    {
        $seconds = array_map(Compiler::leadSeconds(...), $state->leads);

        return count($seconds) !== count(array_unique($seconds));
    }

    private function compensates(TransitionBuilder $transition): bool
    {
        $handler = $transition->handler;

        return $handler instanceof CompensatesTransition
            || (is_string($handler) && is_subclass_of($handler, CompensatesTransition::class));
    }

    /**
     * @param  array<string, scalar>  $params
     */
    private function issue(IssueCode $code, ?string $path, array $params = []): void
    {
        $this->issues[] = new Issue($code, $path, $params);
    }

    private function describe(mixed $state): string
    {
        return match (true) {
            $state instanceof BackedEnum => $state::class.'::'.$state->name,
            is_scalar($state) => (string) $state,
            default => get_debug_type($state),
        };
    }
}
