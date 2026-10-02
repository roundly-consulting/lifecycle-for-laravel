<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition;

use BackedEnum;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use RoundlyConsulting\Lifecycle\Contracts\StateHook;
use RoundlyConsulting\Lifecycle\Definition\Constraints\QuotaRule;
use RoundlyConsulting\Lifecycle\Definition\Constraints\TtlRule;

/**
 * One compiled state.
 */
final readonly class StateDefinition
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  list<QuotaRule>  $quotas
     * @param  list<string>  $stamps
     * @param  list<StateHook|class-string<StateHook>|Closure>  $onEnter
     * @param  list<StateHook|class-string<StateHook>|Closure>  $onExit
     */
    public function __construct(
        public string $key,
        public BackedEnum|string $value,
        public string|Closure|null $label = null,
        public array $meta = [],
        public bool $initial = false,
        public bool $terminal = false,
        public ?TtlRule $ttl = null,
        public array $quotas = [],
        public ?CarbonInterval $minDwell = null,
        public ?CarbonInterval $sealedAfter = null,
        public array $stamps = [],
        public array $onEnter = [],
        public array $onExit = [],
    ) {}

    /**
     * The declared label, else the enum's own `label()`, else the headline of the key passed
     * through the translator (so a host can translate it with a JSON language file).
     */
    public function label(): string
    {
        if (is_string($this->label)) {
            return $this->label;
        }

        if ($this->label instanceof Closure) {
            return (string) ($this->label)();
        }

        if ($this->value instanceof BackedEnum && method_exists($this->value, 'label')) {
            return (string) $this->value->label();
        }

        $headline = Str::headline($this->key);
        $translated = App::make(Translator::class)->get($headline);

        return is_string($translated) ? $translated : $headline;
    }

    public function expires(): bool
    {
        return $this->ttl !== null;
    }
}
