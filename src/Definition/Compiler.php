<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use RoundlyConsulting\Lifecycle\Contracts\CompensatesTransition;
use RoundlyConsulting\Lifecycle\Definition\Constraints\ReversibilityRule;
use RoundlyConsulting\Lifecycle\Definition\Constraints\TtlRule;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleDefinitionException;
use RoundlyConsulting\Lifecycle\Support\Durations;

/**
 * Turns a populated builder into a CompiledDefinition: wildcard sources expanded, warning
 * leads sorted, reversibility decided. Validates first and throws on any error.
 *
 * @internal
 */
final class Compiler
{
    public function compile(string $definition, LifecycleBuilder $builder): CompiledDefinition
    {
        $report = (new DefinitionValidator)->validate($definition, $builder);

        if (! $report->isValid()) {
            throw InvalidLifecycleDefinitionException::fromReport($report);
        }

        $declared = DeclaredStates::from($builder->stateDeclaration);
        $terminal = self::terminalKeys($builder, $declared);
        $initial = (string) $declared->resolve($builder->initial);

        $states = [];

        foreach ($declared->values as $value) {
            $key = $declared->codec($definition)->key($value);
            $state = $builder->stateBuilders[$key] ?? null;

            $states[] = new StateDefinition(
                key: $key,
                value: $value,
                label: $state?->label,
                meta: $state === null ? [] : $state->meta,
                initial: $key === $initial,
                terminal: in_array($key, $terminal, true),
                ttl: $state !== null && $state->hasTtl() ? new TtlRule(
                    interval: $state->ttl,
                    resolver: $state->ttlResolver,
                    attribute: $state->expiresAtAttribute,
                    grace: $state->grace,
                    leads: self::sortLeads($state->leads),
                    expiresVia: (string) $state->expiresVia,
                ) : null,
                quotas: $state === null ? [] : $state->quotas,
                minDwell: $state?->minDwell,
                sealedAfter: $state?->sealedAfter,
                stamps: $state === null ? [] : $state->stamps,
                onEnter: $state === null ? [] : $state->onEnter,
                onExit: $state === null ? [] : $state->onExit,
            );
        }

        $transitions = [];

        foreach ($builder->transitionBuilders as $transition) {
            $handler = $transition->handler;

            $transitions[] = new TransitionDefinition(
                name: $transition->name,
                from: self::sources($transition, $declared, $terminal),
                to: (string) $declared->resolve($transition->to),
                label: $transition->label,
                meta: $transition->meta,
                allowSelf: $transition->allowSelf,
                systemOnly: $transition->systemOnly,
                allowSystem: $transition->allowSystem,
                requiresActor: $transition->requiresActor,
                actorTypes: $transition->actorTypes,
                actorRule: $transition->actorRule,
                ability: $transition->ability,
                requiresReason: $transition->requiresReason,
                reasonMinLength: $transition->reasonMinLength,
                rules: $transition->rules,
                messages: $transition->messages,
                sensitive: $transition->sensitive,
                guards: $transition->guards,
                notBefore: $transition->notBefore,
                notAfter: $transition->notAfter,
                maxOccurrences: $transition->maxOccurrences,
                cooldown: $transition->cooldown,
                rateLimits: $transition->rateLimits,
                ignoresFreeze: $transition->ignoresFreeze,
                ignoresSeal: $transition->ignoresSeal,
                ignoresMinDwell: $transition->ignoresMinDwell,
                handler: $handler,
                reversibility: new ReversibilityRule(
                    irreversible: $transition->irreversible,
                    window: $transition->rollbackWindow,
                    withoutCompensation: $transition->withoutCompensation,
                    compensable: self::compensable($handler),
                    ability: $transition->rollbackAbility,
                    guards: $transition->rollbackGuards,
                ),
                snapshots: $transition->snapshots,
            );
        }

        return new CompiledDefinition(
            class: $definition,
            codec: $declared->codec($definition),
            states: $states,
            initial: $initial,
            transitions: $transitions,
            guards: $builder->guards,
            label: $builder->label,
            meta: $builder->meta,
        );
    }

    /**
     * The expanded source set: `*` is every non-terminal state except the target (unless
     * self-transitions are allowed) minus the excluded ones, plus the explicit sources.
     *
     * @param  list<string>  $terminal
     * @return list<string>
     */
    public static function sources(TransitionBuilder $transition, DeclaredStates $declared, array $terminal): array
    {
        $to = $transition->to === null ? null : $declared->resolve($transition->to);
        $sources = [];

        if ($transition->wildcard) {
            $except = array_map(static fn (mixed $state): ?string => $declared->resolve($state), $transition->except);

            foreach ($declared->keys() as $key) {
                if (in_array($key, $terminal, true) || in_array($key, $except, true)) {
                    continue;
                }

                if ($key === $to && ! $transition->allowSelf) {
                    continue;
                }

                $sources[] = $key;
            }
        }

        foreach ($transition->from as $state) {
            $key = $declared->resolve($state);

            if ($key !== null && ! in_array($key, $sources, true) && ! in_array($key, $terminal, true)) {
                $sources[] = $key;
            }
        }

        // Keep declaration order regardless of how the sources were listed.
        return array_values(array_intersect($declared->keys(), $sources));
    }

    /**
     * @return list<string>
     */
    public static function terminalKeys(LifecycleBuilder $builder, DeclaredStates $declared): array
    {
        $keys = [];

        foreach ($builder->terminal as $state) {
            $key = $declared->resolve($state);

            if ($key !== null && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param  list<CarbonInterval>  $leads
     * @return list<CarbonInterval>
     */
    public static function sortLeads(array $leads): array
    {
        usort($leads, static fn (CarbonInterval $a, CarbonInterval $b): int => self::leadSeconds($b) <=> self::leadSeconds($a));

        return $leads;
    }

    public static function leadSeconds(CarbonInterval $lead): int
    {
        return Durations::seconds($lead, CarbonImmutable::createFromTimestampUTC(946684800));
    }

    private static function compensable(mixed $handler): bool
    {
        return $handler === null
            || $handler instanceof CompensatesTransition
            || (is_string($handler) && is_subclass_of($handler, CompensatesTransition::class));
    }
}
