<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Date;
use RoundlyConsulting\Lifecycle\Contracts\Guard;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\Constraints\DeadlineRule;
use RoundlyConsulting\Lifecycle\Definition\Constraints\RateLimitRule;
use RoundlyConsulting\Lifecycle\Definition\TransitionDefinition;
use RoundlyConsulting\Lifecycle\Enums\DenialCode;
use RoundlyConsulting\Lifecycle\Enums\RateLimitScope;
use RoundlyConsulting\Lifecycle\Exceptions\AmbiguousTransitionException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Support\Durations;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * The one pipeline behind `check()`, `apply()`, schedules, rollbacks, rules and resources.
 * Structural rows (1–3) short-circuit; every other row is evaluated and collected, so a UI
 * can show all the reasons at once.
 *
 * @internal
 */
final readonly class GuardPipeline
{
    public function __construct(
        private Container $container,
        private QuotaGate $quotas,
    ) {}

    /**
     * Rows 1–3: the transition named (or the unique one leading to `$target`), or the
     * structural denial. Several transitions to the target is a usage error.
     */
    public function resolve(CompiledDefinition $definition, ?string $name, mixed $target, string $current): TransitionDefinition|Denial
    {
        $state = $definition->state($current);

        if ($name !== null) {
            $transition = $definition->transition($name);

            if ($transition === null) {
                return Denial::of(DenialCode::UnknownTransition, ['transition' => $name, 'state' => $state->label()], source: 'structure');
            }

            $params = ['transition' => $transition->label(), 'state' => $state->label()];

            if ($state->terminal) {
                return Denial::of(DenialCode::TerminalState, $params, source: 'structure');
            }

            return $transition->leavesFrom($current)
                ? $transition
                : Denial::of(DenialCode::NotFromCurrentState, $params, source: 'structure');
        }

        $targetKey = $definition->key($target);

        if ($state->terminal) {
            return Denial::of(DenialCode::TerminalState, ['transition' => '', 'state' => $state->label()], source: 'structure');
        }

        $candidates = $definition->transitionsBetween($current, $targetKey);

        if (count($candidates) > 1) {
            throw AmbiguousTransitionException::between(
                $current,
                $targetKey,
                array_map(static fn (TransitionDefinition $transition): string => $transition->name, $candidates),
            );
        }

        return $candidates[0] ?? Denial::of(
            DenialCode::NoTransitionToState,
            ['transition' => '', 'state' => $definition->stateLabel($targetKey)],
            source: 'structure',
        );
    }

    /**
     * The structural refusal of a soft-deleted subject in the check paths — `apply()` and
     * `rollback()` throw SubjectTrashedException under the lock instead.
     */
    public static function trashed(CompiledDefinition $definition, ?string $current, ?string $transition): Denial
    {
        $label = $transition === null ? '' : ($definition->transition($transition)?->label() ?? $transition);

        return Denial::of(DenialCode::SubjectTrashed, [
            'transition' => $label,
            'state' => $current === null ? '' : $definition->stateLabel($current),
        ], source: 'structure');
    }

    /**
     * Rows 4–18, collected; row 19 (rate limits) only when everything else passed — `check()`
     * peeks at it, `apply()` consumes it afterwards ({@see self::consumeRateLimits()}).
     */
    public function evaluate(Evaluation $evaluation): Decision
    {
        $denials = [
            ...$this->record($evaluation),
            ...$this->context($evaluation),
            ...$this->actor($evaluation),
            ...$this->input($evaluation),
            ...$this->deadlines($evaluation),
            ...$this->limits($evaluation),
            ...$this->guards($evaluation),
        ];

        // A doomed apply counts in Check mode: no cross-subject mutex taken for nothing.
        $denials = [...$denials, ...$this->quotas->check($evaluation, lock: $evaluation->mode === Mode::Apply && $denials === [])];

        if ($denials === [] && $evaluation->mode === Mode::Check) {
            $denials = $this->rateLimits($evaluation, consume: false);
        }

        return Decision::from($denials);
    }

    /**
     * The schedule-time subset (§3.5): freeze and seal, who may schedule it (a `systemOnly()`
     * transition only from system code; it must allow system context at all), the scheduling
     * actor's rules, the reason and payload — which are stored — and no `sensitive()` key may
     * be required, since sensitive keys are never stored. Time rows, quotas and rate limits
     * are evaluated when the schedule runs.
     */
    public function evaluateSchedule(Evaluation $evaluation): Decision
    {
        $transition = $evaluation->context->transition;
        $params = $this->params($evaluation);
        $denials = $this->record($evaluation);

        if (! $transition->allowsSystem()) {
            $denials[] = Denial::of(DenialCode::SystemNotAllowed, $params, source: 'context');
        } elseif ($transition->systemOnly && ! $evaluation->context->system) {
            $denials[] = Denial::of(DenialCode::SystemOnly, $params, source: 'context');
        }

        $denials = [...$denials, ...$this->actor($evaluation), ...$this->input($evaluation)];

        foreach ($transition->sensitive as $key) {
            $rule = $transition->rules[$key] ?? [];
            $rules = is_string($rule) ? explode('|', $rule) : (is_array($rule) ? $rule : []);

            if (in_array('required', $rules, true)) {
                $denials[] = Denial::of(DenialCode::InvalidPayload, $params, errors: [$key => ['A sensitive value cannot be scheduled.']], source: 'input');
            }
        }

        return Decision::from($denials);
    }

    /**
     * Who may roll a row back (user context): the reverted transition's `rollbackRequires()` /
     * `rollbackGuard()` when declared, otherwise what applying it needed — its own actor rules
     * (rows 8–10) for the rolling-back actor, and system context for a `systemOnly()` one (row
     * 7). Nobody can undo what they could not have done.
     *
     * @return list<Denial>
     */
    public function rollbackActor(Evaluation $evaluation): array
    {
        $context = $evaluation->context;
        $rule = $context->transition->reversibility;

        if ($context->system) {
            return [];
        }

        if (! $rule->hasActorRules()) {
            return $context->transition->systemOnly
                ? [Denial::of(DenialCode::SystemOnly, $this->params($evaluation), source: 'context')]
                : $this->actor($evaluation);
        }

        $params = $this->params($evaluation);
        $denials = [];

        if ($rule->ability !== null) {
            if ($context->actor === null) {
                return [Denial::of(DenialCode::ActorRequired, $params, source: 'actor')];
            }

            if (! $this->container->make(Gate::class)->forUser($context->actor)->allows($rule->ability, [$context->subject, $context])) {
                $denials[] = Denial::of(DenialCode::Unauthorized, $params, source: 'actor');
            }
        }

        foreach ($rule->guards as $guard) {
            $denial = $this->resolveGuard($guard)->check($context);

            if ($denial !== null) {
                $denials[] = $denial;
            }
        }

        return $denials;
    }

    /**
     * Row 19 in Apply mode: count one hit per rule; a rule over its limit refuses. Hits are
     * never refunded, even when the transaction later fails.
     */
    public function consumeRateLimits(Evaluation $evaluation): Decision
    {
        return Decision::from($this->rateLimits($evaluation, consume: true));
    }

    /**
     * Rows 4–6: the expected version, the freeze and the seal.
     *
     * @return list<Denial>
     */
    private function record(Evaluation $evaluation): array
    {
        $record = $evaluation->record;
        $context = $evaluation->context;
        $transition = $context->transition;
        $params = $this->params($evaluation);
        $denials = [];

        if ($evaluation->expectedVersion !== null && ($record === null ? 0 : $record->version) !== $evaluation->expectedVersion) {
            $denials[] = Denial::of(DenialCode::StaleVersion, $params, source: 'record');
        }

        if ($record === null) {
            return $denials;
        }

        if (! $transition->ignoresFreeze && $record->isFrozen($context->now)) {
            $denials[] = Denial::of(DenialCode::Frozen, $params, source: 'record');
        }

        $sealedAfter = $evaluation->definition->state($evaluation->fromKey())->sealedAfter;

        if (! $transition->ignoresSeal && $sealedAfter !== null
            && $context->now->greaterThanOrEqualTo(Durations::add($evaluation->enteredAt(), $sealedAfter))) {
            $denials[] = Denial::of(DenialCode::Sealed, $params, source: 'record');
        }

        return $denials;
    }

    /**
     * Rows 14–16: minimum dwell and cooldown (user context), maximum occurrences (always).
     * Counts come from the record, never from history, so pruning cannot reset them.
     *
     * @return list<Denial>
     */
    private function limits(Evaluation $evaluation): array
    {
        $record = $evaluation->record;
        $context = $evaluation->context;
        $transition = $context->transition;
        $params = $this->params($evaluation);
        $denials = [];

        $dwell = $evaluation->definition->state($evaluation->fromKey())->minDwell;

        // Without a record yet, apply() creates one now: the stay — and so the dwell — starts now.
        if (! $context->system && ! $transition->ignoresMinDwell && $dwell !== null) {
            $until = Durations::add($evaluation->enteredAt(), $dwell);

            if ($context->now->lessThan($until)) {
                $denials[] = Denial::of(DenialCode::MinDwellNotReached, [...$params, 'at' => self::display($until)], retryAfter: $until, source: 'limit');
            }
        }

        $last = CounterBook::lastAt($record?->counters, $transition->name);

        if (! $context->system && $transition->cooldown !== null && $last !== null) {
            $until = Durations::add($last, $transition->cooldown);

            if ($context->now->lessThan($until)) {
                $denials[] = Denial::of(DenialCode::CooldownActive, [...$params, 'at' => self::display($until)], retryAfter: $until, source: 'limit');
            }
        }

        if ($transition->maxOccurrences !== null && CounterBook::count($record?->counters, $transition->name) >= $transition->maxOccurrences) {
            $denials[] = Denial::of(DenialCode::MaxOccurrencesReached, [...$params, 'max' => $transition->maxOccurrences], source: 'limit');
        }

        return $denials;
    }

    /**
     * Row 19, user context only.
     *
     * @return list<Denial>
     */
    private function rateLimits(Evaluation $evaluation, bool $consume): array
    {
        $context = $evaluation->context;

        if ($context->system) {
            return [];
        }

        $limiter = $this->container->make(RateLimiter::class);
        $denials = [];

        foreach ($context->transition->rateLimits as $rule) {
            $key = $this->rateLimitKey($evaluation, $rule);
            $exceeded = $consume
                ? $limiter->hit($key, Durations::seconds($rule->decay, $context->now)) > $rule->maxAttempts
                : $limiter->tooManyAttempts($key, $rule->maxAttempts);

            if ($exceeded) {
                $at = $context->now->addSeconds($limiter->availableIn($key));
                $denials[] = Denial::of(DenialCode::RateLimited, [...$this->params($evaluation), 'at' => self::display($at)], retryAfter: $at, source: 'rate_limit');
            }
        }

        return $denials;
    }

    /**
     * JSON-encoded parts — no delimiter a value could forge — URL-encoded, so the rate limiter's
     * own key cleaning (which collapses HTML entities: `&` → `a`, `é` → `e`) has nothing left to
     * merge. Per-actor limits of an actor-less call count per subject instead of in one shared
     * bucket for everyone without an actor.
     */
    private function rateLimitKey(Evaluation $evaluation, RateLimitRule $rule): string
    {
        $context = $evaluation->context;
        $actor = $context->actor;
        $parts = [];

        if ($rule->per !== RateLimitScope::Subject && $actor !== null) {
            $parts[] = [$actor->getMorphClass(), $actor->getKey()];
        }

        if ($rule->per !== RateLimitScope::Actor || $actor === null) {
            $parts[] = [$context->subject->getMorphClass(), $context->subject->getKey()];
        }

        $prefix = config('lifecycle.rate_limits.prefix', 'lifecycle');

        return rawurlencode(json_encode(
            [is_string($prefix) ? $prefix : 'lifecycle', $evaluation->definition->class, $context->transition->name, $parts],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * Row 7: user context may not run system-only transitions; system context may run only
     * transitions that allow it.
     *
     * @return list<Denial>
     */
    private function context(Evaluation $evaluation): array
    {
        $transition = $evaluation->context->transition;
        $params = $this->params($evaluation);

        if (! $evaluation->context->system && $transition->systemOnly) {
            return [Denial::of(DenialCode::SystemOnly, $params, source: 'context')];
        }

        if ($evaluation->context->system && ! $transition->allowsSystem()) {
            return [Denial::of(DenialCode::SystemNotAllowed, $params, source: 'context')];
        }

        return [];
    }

    /**
     * Rows 8–10, user context only: an actor when any actor rule exists, the actor types
     * and closure, the Gate ability. A transition without actor rules is callable by any
     * code path.
     *
     * @return list<Denial>
     */
    private function actor(Evaluation $evaluation): array
    {
        $context = $evaluation->context;
        $transition = $context->transition;

        if ($context->system || ! $transition->hasActorRules()) {
            return [];
        }

        $params = $this->params($evaluation);

        if ($context->actor === null) {
            return [Denial::of(DenialCode::ActorRequired, $params, source: 'actor')];
        }

        $denials = [];
        $actor = $context->actor;

        $typeAllowed = $transition->actorTypes === [] || array_filter(
            $transition->actorTypes,
            static fn (string $type): bool => $actor instanceof $type,
        ) !== [];

        if (! $typeAllowed || ($transition->actorRule !== null && ($transition->actorRule)($actor, $context->subject) !== true)) {
            $denials[] = Denial::of(DenialCode::ActorNotAllowed, $params, source: 'actor');
        }

        if ($transition->ability !== null && ! $this->container->make(Gate::class)->forUser($actor)->allows($transition->ability, [$context->subject, $context])) {
            $denials[] = Denial::of(DenialCode::Unauthorized, $params, source: 'actor');
        }

        return $denials;
    }

    /**
     * Rows 11–12. `check()` evaluates the reason and payload only when they were supplied —
     * otherwise every transition that needs input would render as denied; `apply()` always.
     *
     * @return list<Denial>
     */
    private function input(Evaluation $evaluation): array
    {
        $context = $evaluation->context;
        $transition = $context->transition;
        $apply = $evaluation->mode === Mode::Apply;
        $denials = [];

        if (! $context->system && $transition->requiresReason && ($apply || $evaluation->reasonGiven)
            && mb_strlen(trim((string) $context->reason)) < $transition->reasonMinLength) {
            $denials[] = Denial::of(DenialCode::ReasonRequired, $this->params($evaluation), source: 'input');
        }

        $tooLong = self::reasonTooLong($context->reason, $this->params($evaluation));

        if ($tooLong !== null) {
            $denials[] = $tooLong;
        }

        if ($apply || $evaluation->payloadGiven) {
            if ($evaluation->payloadErrors !== []) {
                $denials[] = Denial::of(DenialCode::InvalidPayload, $this->params($evaluation), errors: $evaluation->payloadErrors, source: 'input');
            } elseif ($evaluation->contextTooLarge) {
                $denials[] = Denial::of(DenialCode::InvalidPayload, $this->params($evaluation), source: 'input');
            }
        }

        return $denials;
    }

    /**
     * `history.reason_max_length`, for every reason the package stores (transitions, schedules,
     * rollbacks, freezes).
     */
    public static function reasonMaxLength(): int
    {
        return Config::using(InvalidLifecycleConfigurationException::class)
            ->intBetween('lifecycle.history.reason_max_length', 1, 10000, 1000);
    }

    /**
     * @param  array<string, scalar>  $params
     */
    public static function reasonTooLong(?string $reason, array $params): ?Denial
    {
        $max = self::reasonMaxLength();

        return $reason !== null && mb_strlen($reason) > $max
            ? Denial::of(DenialCode::ReasonTooLong, [...$params, 'max' => $max], source: 'input')
            : null;
    }

    /**
     * Row 13: not before an instant (retryable, with the instant), not after a deadline (the
     * deadline itself is still allowed). A null instant never blocks.
     *
     * @return list<Denial>
     */
    private function deadlines(Evaluation $evaluation): array
    {
        $context = $evaluation->context;
        $transition = $context->transition;
        $denials = [];

        $notBefore = $this->instant($transition->notBefore, $context);

        if ($notBefore !== null && $context->now->lessThan($notBefore)) {
            $denials[] = Denial::of(
                DenialCode::NotYetAvailable,
                [...$this->params($evaluation), 'at' => self::display($notBefore)],
                retryAfter: $notBefore,
                source: 'deadline',
            );
        }

        $notAfter = $this->instant($transition->notAfter, $context);

        if ($notAfter !== null && $context->now->greaterThan($notAfter)) {
            $denials[] = Denial::of(DenialCode::DeadlinePassed, [...$this->params($evaluation), 'at' => self::display($notAfter)], source: 'deadline');
        }

        return $denials;
    }

    /**
     * Row 17: lifecycle-wide guards, then the transition's, in declaration order. Class
     * strings are resolved from the container on every evaluation.
     *
     * @return list<Denial>
     */
    private function guards(Evaluation $evaluation): array
    {
        $denials = [];

        foreach ([...$evaluation->definition->guards, ...$evaluation->context->transition->guards] as $guard) {
            $denial = $this->resolveGuard($guard)->check($evaluation->context);

            if ($denial !== null) {
                $denials[] = $denial;
            }
        }

        return $denials;
    }

    /**
     * @param  Guard|class-string<Guard>  $guard
     */
    public function resolveGuard(Guard|string $guard): Guard
    {
        $resolved = is_string($guard) ? $this->container->make($guard) : $guard;

        return $resolved instanceof Guard ? $resolved : throw InvalidLifecycleUsageException::invalidExtension('guard', $guard);
    }

    private function instant(?DeadlineRule $rule, TransitionContext $context): ?CarbonImmutable
    {
        if ($rule === null) {
            return null;
        }

        $value = $rule->source instanceof Closure
            ? ($rule->source)($context->subject)
            : $context->subject->getAttribute($rule->source);

        $instant = self::toUtc($value, $rule->source instanceof Closure ? 'notBefore/notAfter closure' : $rule->source);

        return $instant === null || $rule->offset === null ? $instant : Durations::add($instant, $rule->offset);
    }

    /**
     * A host datetime: a DateTimeInterface converted to UTC, a string parsed in the
     * application timezone (as Eloquent does), null for "none".
     */
    public static function toUtc(mixed $value, string $attribute): ?CarbonImmutable
    {
        return match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->utc(),
            is_string($value) && $value !== '' => CarbonImmutable::instance(Date::parse($value))->utc(),
            default => throw InvalidLifecycleUsageException::invalidDateAttribute($attribute, $value),
        };
    }

    /**
     * An instant as the user reads it: in the application timezone.
     */
    public static function display(CarbonImmutable $instant): string
    {
        return $instant->setTimezone(date_default_timezone_get())->format('Y-m-d H:i');
    }

    /**
     * @return array<string, scalar>
     */
    public function params(Evaluation $evaluation): array
    {
        return [
            'transition' => $evaluation->context->transition->label(),
            'state' => $evaluation->definition->stateLabel($evaluation->fromKey()),
        ];
    }
}
