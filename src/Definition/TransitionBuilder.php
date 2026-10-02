<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition;

use BackedEnum;
use Carbon\CarbonInterval;
use Closure;
use DateInterval;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Contracts\Guard;
use RoundlyConsulting\Lifecycle\Contracts\TransitionHandler;
use RoundlyConsulting\Lifecycle\Definition\Constraints\DeadlineRule;
use RoundlyConsulting\Lifecycle\Definition\Constraints\RateLimitRule;
use RoundlyConsulting\Lifecycle\Engine\ClosureGuard;
use RoundlyConsulting\Lifecycle\Enums\IssueCode;
use RoundlyConsulting\Lifecycle\Enums\RateLimitScope;
use RoundlyConsulting\Lifecycle\Support\Durations;
use RoundlyConsulting\Lifecycle\Support\Identifiers;

/**
 * One named transition: its sources and target, who may run it, and every restriction and
 * limitation that applies. Invalid input is recorded as a validation issue.
 */
final class TransitionBuilder
{
    /** @var list<BackedEnum|string|int> */
    public private(set) array $from = [];

    public private(set) bool $wildcard = false;

    /** @var list<BackedEnum|string|int> */
    public private(set) array $except = [];

    public private(set) BackedEnum|string|int|null $to = null;

    public private(set) ?string $label = null;

    /** @var array<string, mixed> */
    public private(set) array $meta = [];

    public private(set) bool $allowSelf = false;

    public private(set) bool $systemOnly = false;

    public private(set) bool $allowSystem = false;

    public private(set) bool $requiresActor = false;

    /** @var list<class-string<Model>> */
    public private(set) array $actorTypes = [];

    public private(set) ?Closure $actorRule = null;

    public private(set) ?string $ability = null;

    public private(set) bool $requiresReason = false;

    public private(set) int $reasonMinLength = 1;

    /** @var array<string, mixed> */
    public private(set) array $rules = [];

    /** @var array<string, string> */
    public private(set) array $messages = [];

    /** @var list<string> */
    public private(set) array $sensitive = [];

    /** @var list<Guard|class-string<Guard>> */
    public private(set) array $guards = [];

    public private(set) ?DeadlineRule $notBefore = null;

    public private(set) ?DeadlineRule $notAfter = null;

    public private(set) ?int $maxOccurrences = null;

    public private(set) ?CarbonInterval $cooldown = null;

    /** @var list<RateLimitRule> */
    public private(set) array $rateLimits = [];

    public private(set) bool $ignoresFreeze = false;

    public private(set) bool $ignoresSeal = false;

    public private(set) bool $ignoresMinDwell = false;

    /** @var TransitionHandler|class-string<TransitionHandler>|Closure|null */
    public private(set) TransitionHandler|string|Closure|null $handler = null;

    public private(set) bool $irreversible = false;

    public private(set) ?CarbonInterval $rollbackWindow = null;

    public private(set) bool $withoutCompensation = false;

    public private(set) ?string $rollbackAbility = null;

    /** @var list<Guard|class-string<Guard>> */
    public private(set) array $rollbackGuards = [];

    /** @var list<string> */
    public private(set) array $snapshots = [];

    /** @var list<Issue> */
    public private(set) array $issues = [];

    public function __construct(
        public readonly string $name,
    ) {}

    /**
     * Source states; `'*'` is every non-terminal state except the target.
     */
    public function from(BackedEnum|string|int ...$states): static
    {
        foreach ($states as $state) {
            if ($state === '*') {
                $this->wildcard = true;

                continue;
            }

            $this->from[] = $state;
        }

        return $this;
    }

    /**
     * Every non-terminal state except the target and the listed ones.
     */
    public function fromAnyExcept(BackedEnum|string|int ...$states): static
    {
        $this->wildcard = true;
        $this->except = [...$this->except, ...array_values($states)];

        return $this;
    }

    public function to(BackedEnum|string|int $state): static
    {
        $this->to = $state;

        return $this;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): static
    {
        $this->meta = $meta;

        return $this;
    }

    public function allowSelf(): static
    {
        $this->allowSelf = true;

        return $this;
    }

    /**
     * Only system code (`asSystem()`, sweeps, jobs) may run it.
     */
    public function systemOnly(): static
    {
        $this->systemOnly = true;

        return $this;
    }

    /**
     * Users and system code may both run it (required for scheduled transitions).
     */
    public function allowSystem(): static
    {
        $this->allowSystem = true;

        return $this;
    }

    public function requiresActor(): static
    {
        $this->requiresActor = true;

        return $this;
    }

    /**
     * @param  class-string<Model>  ...$types
     */
    public function actors(string ...$types): static
    {
        $this->actorTypes = [...$this->actorTypes, ...array_values($types)];

        return $this;
    }

    /**
     * @param  Closure(?Model, Model): bool  $rule
     */
    public function actor(Closure $rule): static
    {
        $this->actorRule = $rule;

        return $this;
    }

    /**
     * A Gate ability checked as `allows($ability, [$subject, $context])`.
     */
    public function ability(string $ability): static
    {
        $this->ability = $ability;

        return $this;
    }

    public function requiresReason(int $minLength = 1): static
    {
        $this->requiresReason = true;
        $this->reasonMinLength = max(1, $minLength);

        return $this;
    }

    /**
     * Validation rules for the payload; only validated keys are kept.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     */
    public function rules(array $rules, array $messages = []): static
    {
        $this->rules = $rules;
        $this->messages = $messages;

        return $this;
    }

    /**
     * Payload keys never stored in history, schedules or events.
     */
    public function sensitive(string ...$keys): static
    {
        $this->sensitive = [...$this->sensitive, ...array_values($keys)];

        return $this;
    }

    /**
     * @param  Guard|class-string<Guard>|Closure  $guard
     */
    public function when(Guard|string|Closure $guard, ?string $code = null, ?string $message = null): static
    {
        $this->guards[] = $guard instanceof Closure ? new ClosureGuard($guard, $code, $message) : $guard;

        return $this;
    }

    /**
     * Not allowed before an instant: an attribute name, or `fn (Model $subject): ?CarbonInterface`.
     */
    public function notBefore(Closure|string $attribute, CarbonInterval|string|null $offset = null): static
    {
        $this->notBefore = $this->deadline($attribute, $offset, 'notBefore');

        return $this;
    }

    /**
     * Not allowed after an instant (the instant itself is still allowed).
     */
    public function notAfter(Closure|string $attribute, CarbonInterval|string|null $offset = null): static
    {
        $this->notAfter = $this->deadline($attribute, $offset, 'notAfter');

        return $this;
    }

    public function maxOccurrences(int $max): static
    {
        $this->maxOccurrences = $max;

        if ($max < 1) {
            $this->issues[] = new Issue(IssueCode::InvalidMaxOccurrences, 'transition:'.$this->name, ['value' => $max]);
        }

        return $this;
    }

    public function cooldown(CarbonInterval|DateInterval|string $cooldown): static
    {
        $this->cooldown = $this->duration($cooldown, 'cooldown');

        return $this;
    }

    public function rateLimit(int $maxAttempts, CarbonInterval|DateInterval|string $decay, RateLimitScope $per = RateLimitScope::Actor): static
    {
        $interval = $this->duration($decay, 'rateLimit');

        if ($maxAttempts < 1) {
            $this->issues[] = new Issue(IssueCode::InvalidRateLimit, 'transition:'.$this->name, ['value' => $maxAttempts]);

            return $this;
        }

        if ($interval !== null) {
            $this->rateLimits[] = new RateLimitRule($maxAttempts, $interval, $per);
        }

        return $this;
    }

    public function ignoresFreeze(): static
    {
        $this->ignoresFreeze = true;

        return $this;
    }

    public function ignoresSeal(): static
    {
        $this->ignoresSeal = true;

        return $this;
    }

    public function ignoresMinDwell(): static
    {
        $this->ignoresMinDwell = true;

        return $this;
    }

    /**
     * Side effects run inside the transaction after the state write.
     *
     * @param  TransitionHandler|class-string<TransitionHandler>|Closure  $handler
     */
    public function handledBy(TransitionHandler|string|Closure $handler): static
    {
        $this->handler = $handler;

        return $this;
    }

    public function irreversible(): static
    {
        $this->irreversible = true;

        return $this;
    }

    public function reversible(CarbonInterval|DateInterval|string|null $within = null, bool $withoutCompensation = false): static
    {
        $this->irreversible = false;
        $this->withoutCompensation = $withoutCompensation;
        $this->rollbackWindow = $within === null ? null : $this->duration($within, 'reversible');

        return $this;
    }

    public function rollbackRequires(string $ability): static
    {
        $this->rollbackAbility = $ability;

        return $this;
    }

    /**
     * @param  Guard|class-string<Guard>|Closure  $guard
     */
    public function rollbackGuard(Guard|string|Closure $guard): static
    {
        $this->rollbackGuards[] = $guard instanceof Closure ? new ClosureGuard($guard) : $guard;

        return $this;
    }

    /**
     * Attributes captured before and after the transition, restored on rollback.
     */
    public function snapshots(string ...$attributes): static
    {
        foreach ($attributes as $attribute) {
            if (! Identifiers::isColumn($attribute)) {
                $this->invalidIdentifier('snapshots', $attribute);

                continue;
            }

            if (! in_array($attribute, $this->snapshots, true)) {
                $this->snapshots[] = $attribute;
            }
        }

        return $this;
    }

    public function hasActorRules(): bool
    {
        return $this->requiresActor || $this->actorTypes !== [] || $this->actorRule !== null || $this->ability !== null;
    }

    private function deadline(Closure|string $source, CarbonInterval|string|null $offset, string $setting): ?DeadlineRule
    {
        if (is_string($source) && ! Identifiers::isColumn($source)) {
            $this->invalidIdentifier($setting, $source);

            return null;
        }

        $interval = null;

        if ($offset !== null) {
            $interval = Durations::tryOffset($offset);

            if ($interval === null) {
                $this->issues[] = new Issue(IssueCode::InvalidDuration, 'transition:'.$this->name, [
                    'setting' => $setting,
                    'value' => is_string($offset) ? $offset : get_debug_type($offset),
                ]);

                return null;
            }
        }

        return new DeadlineRule($source, $interval);
    }

    private function duration(CarbonInterval|DateInterval|string $value, string $setting): ?CarbonInterval
    {
        $parsed = Durations::tryParse($value);

        if ($parsed === null) {
            $this->issues[] = new Issue(IssueCode::InvalidDuration, 'transition:'.$this->name, [
                'setting' => $setting,
                'value' => is_string($value) ? $value : get_debug_type($value),
            ]);
        }

        return $parsed;
    }

    private function invalidIdentifier(string $setting, string $value): void
    {
        $this->issues[] = new Issue(IssueCode::InvalidIdentifier, 'transition:'.$this->name, [
            'setting' => $setting,
            'value' => $value,
        ]);
    }
}
