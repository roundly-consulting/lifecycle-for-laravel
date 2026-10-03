<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition;

use BackedEnum;
use Carbon\CarbonInterval;
use Closure;
use DateInterval;
use RoundlyConsulting\Lifecycle\Contracts\StateHook;
use RoundlyConsulting\Lifecycle\Definition\Constraints\QuotaRule;
use RoundlyConsulting\Lifecycle\Enums\IssueCode;
use RoundlyConsulting\Lifecycle\Support\Durations;
use RoundlyConsulting\Lifecycle\Support\Identifiers;

/**
 * Per-state settings: label, expiry (TTL, grace, warnings), dwell, seal, quotas, stamps and
 * hooks. Invalid input is recorded as a validation issue rather than thrown, so
 * `lifecycle:validate` can report every problem of a definition at once.
 */
final class StateBuilder
{
    public private(set) string|Closure|null $label = null;

    /** @var array<string, mixed> */
    public private(set) array $meta = [];

    public private(set) ?CarbonInterval $ttl = null;

    public private(set) ?Closure $ttlResolver = null;

    public private(set) ?string $expiresAtAttribute = null;

    public private(set) bool $graceDeclared = false;

    public private(set) ?CarbonInterval $grace = null;

    public private(set) bool $leadsDeclared = false;

    /** @var list<CarbonInterval> */
    public private(set) array $leads = [];

    public private(set) int $declaredLeadCount = 0;

    public private(set) ?string $expiresVia = null;

    public private(set) ?CarbonInterval $minDwell = null;

    public private(set) ?CarbonInterval $sealedAfter = null;

    /** @var list<QuotaRule> */
    public private(set) array $quotas = [];

    /** @var list<string> */
    public private(set) array $stamps = [];

    /** @var list<StateHook|class-string<StateHook>|Closure> */
    public private(set) array $onEnter = [];

    /** @var list<StateHook|class-string<StateHook>|Closure> */
    public private(set) array $onExit = [];

    /** @var list<Issue> */
    public private(set) array $issues = [];

    public readonly string $key;

    public function __construct(
        public readonly BackedEnum|string|int $state,
    ) {
        $this->key = (string) ($state instanceof BackedEnum ? $state->value : $state);
    }

    public function label(string|Closure $label): static
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

    /**
     * A fixed interval from entering the state, or a closure `fn (Model $subject)` returning
     * an interval, an instant, or null for "never expires".
     */
    public function ttl(CarbonInterval|DateInterval|string|Closure $ttl): static
    {
        if ($ttl instanceof Closure) {
            $this->ttlResolver = $ttl;
            $this->ttl = null;

            return $this;
        }

        $this->ttlResolver = null;
        $this->ttl = $this->duration($ttl, 'ttl');

        return $this;
    }

    /**
     * The subject's own datetime attribute is the expiry instant (null = never expires).
     */
    public function expiresAtAttribute(string $attribute): static
    {
        $this->expiresAtAttribute = $this->column($attribute, 'expiresAtAttribute');

        return $this;
    }

    public function grace(CarbonInterval|DateInterval|string $grace): static
    {
        $this->graceDeclared = true;
        $this->grace = $this->duration($grace, 'grace');

        return $this;
    }

    public function warnBefore(CarbonInterval|DateInterval|string ...$leads): static
    {
        $this->leadsDeclared = true;
        $this->declaredLeadCount = count($leads);
        $this->leads = [];

        foreach ($leads as $lead) {
            $parsed = $this->duration($lead, 'warnBefore');

            if ($parsed !== null) {
                $this->leads[] = $parsed;
            }
        }

        return $this;
    }

    public function expiresVia(string $transition): static
    {
        $this->expiresVia = $transition;

        return $this;
    }

    public function minDwell(CarbonInterval|DateInterval|string $dwell): static
    {
        $this->minDwell = $this->duration($dwell, 'minDwell');

        return $this;
    }

    public function sealedAfter(CarbonInterval|DateInterval|string $after): static
    {
        $this->sealedAfter = $this->duration($after, 'sealedAfter');

        return $this;
    }

    /**
     * @param  int|Closure  $max  a fixed maximum, or `fn (Model $subject): int`
     * @param  string|list<string>  $scope  columns that partition the count (e.g. `user_id`)
     */
    public function quota(int|Closure $max, string|array $scope = [], ?string $name = null): static
    {
        $columns = [];

        foreach ((array) $scope as $column) {
            $columns[] = $this->column($column, 'quota');
        }

        $columns = array_values(array_filter($columns, static fn (?string $column): bool => $column !== null));
        sort($columns);

        $this->quotas[] = new QuotaRule($max, $columns, $name ?? self::defaultQuotaName($this->key.'|'.implode(',', $columns)));

        return $this;
    }

    /**
     * Host datetime columns written with the subject's timestamp when a transition enters
     * this state (`paid_at`); restored on rollback.
     */
    public function stamps(string ...$columns): static
    {
        foreach ($columns as $column) {
            $valid = $this->column($column, 'stamps');

            if ($valid !== null && ! in_array($valid, $this->stamps, true)) {
                $this->stamps[] = $valid;
            }
        }

        return $this;
    }

    /**
     * @param  StateHook|class-string<StateHook>|Closure  $hook
     */
    public function onEnter(StateHook|string|Closure $hook): static
    {
        $this->onEnter[] = $hook;

        return $this;
    }

    /**
     * @param  StateHook|class-string<StateHook>|Closure  $hook
     */
    public function onExit(StateHook|string|Closure $hook): static
    {
        $this->onExit[] = $hook;

        return $this;
    }

    public function hasTtl(): bool
    {
        return $this->ttl !== null || $this->ttlResolver !== null || $this->expiresAtAttribute !== null;
    }

    private function duration(CarbonInterval|DateInterval|string $value, string $setting): ?CarbonInterval
    {
        $parsed = Durations::tryParse($value);

        if ($parsed === null) {
            $this->issues[] = new Issue(IssueCode::InvalidDuration, 'state:'.$this->key, [
                'setting' => $setting,
                'value' => is_string($value) ? $value : get_debug_type($value),
            ]);
        }

        return $parsed;
    }

    private function column(string $column, string $setting): ?string
    {
        if (Identifiers::isColumn($column)) {
            return $column;
        }

        $this->issues[] = new Issue(IssueCode::InvalidIdentifier, 'state:'.$this->key, [
            'setting' => $setting,
            'value' => $column,
        ]);

        return null;
    }

    /**
     * `"{state}|{sorted scope columns}"`, kept within the 64 characters a quota name may have:
     * a longer one keeps its first 55 characters plus a checksum of the whole, so two long
     * names stay distinct (and a collision would still surface as `duplicate_quota_name`).
     */
    private static function defaultQuotaName(string $name): string
    {
        return mb_strlen($name) <= 64 ? $name : mb_substr($name, 0, 55).'~'.sprintf('%08x', crc32($name));
    }
}
