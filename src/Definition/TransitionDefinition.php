<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use RoundlyConsulting\Lifecycle\Contracts\Guard;
use RoundlyConsulting\Lifecycle\Contracts\TransitionHandler;
use RoundlyConsulting\Lifecycle\Definition\Constraints\DeadlineRule;
use RoundlyConsulting\Lifecycle\Definition\Constraints\RateLimitRule;
use RoundlyConsulting\Lifecycle\Definition\Constraints\ReversibilityRule;

/**
 * One compiled transition. `from` is the expanded source set (wildcards resolved).
 */
final readonly class TransitionDefinition
{
    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $meta
     * @param  list<class-string<Model>>  $actorTypes
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @param  list<string>  $sensitive
     * @param  list<Guard|class-string<Guard>>  $guards
     * @param  list<RateLimitRule>  $rateLimits
     * @param  TransitionHandler|class-string<TransitionHandler>|Closure|null  $handler
     * @param  list<string>  $snapshots
     */
    public function __construct(
        public string $name,
        public array $from,
        public string $to,
        public ?string $label = null,
        public array $meta = [],
        public bool $allowSelf = false,
        public bool $systemOnly = false,
        public bool $allowSystem = false,
        public bool $requiresActor = false,
        public array $actorTypes = [],
        public ?Closure $actorRule = null,
        public ?string $ability = null,
        public bool $requiresReason = false,
        public int $reasonMinLength = 1,
        public array $rules = [],
        public array $messages = [],
        public array $sensitive = [],
        public array $guards = [],
        public ?DeadlineRule $notBefore = null,
        public ?DeadlineRule $notAfter = null,
        public ?int $maxOccurrences = null,
        public ?CarbonInterval $cooldown = null,
        public array $rateLimits = [],
        public bool $ignoresFreeze = false,
        public bool $ignoresSeal = false,
        public bool $ignoresMinDwell = false,
        public TransitionHandler|string|Closure|null $handler = null,
        public ReversibilityRule $reversibility = new ReversibilityRule,
        public array $snapshots = [],
    ) {}

    public function label(): string
    {
        if ($this->label !== null) {
            return $this->label;
        }

        $headline = Str::headline($this->name);
        $translated = App::make(Translator::class)->get($headline);

        return is_string($translated) ? $translated : $headline;
    }

    /**
     * Rows 8–10 of the pipeline apply only when one of these is declared.
     */
    public function hasActorRules(): bool
    {
        return $this->requiresActor || $this->actorTypes !== [] || $this->actorRule !== null || $this->ability !== null;
    }

    /**
     * System context (`asSystem()`, sweeps) may run it.
     */
    public function allowsSystem(): bool
    {
        return $this->systemOnly || $this->allowSystem;
    }

    public function leavesFrom(string $state): bool
    {
        return in_array($state, $this->from, true);
    }

    public function isSelfFrom(string $state): bool
    {
        return $state === $this->to;
    }

    /**
     * Top-level payload keys the rules describe — what a UI should ask for.
     *
     * @return list<string>
     */
    public function payloadFields(): array
    {
        $fields = [];

        foreach (array_keys($this->rules) as $key) {
            $field = explode('.', (string) $key)[0];

            if (! in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
