<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;

/**
 * Everything one pass of the guard pipeline reads: the context guards see, the state record
 * (locked in Apply mode), and what the caller supplied.
 *
 * @internal
 */
final readonly class Evaluation
{
    /**
     * @param  array<string, list<string>>  $payloadErrors
     * @param  array<string, mixed>  $storedContext  what history would store (validated payload minus sensitive keys)
     */
    public function __construct(
        public CompiledDefinition $definition,
        public TransitionContext $context,
        public Mode $mode,
        public ?LifecycleState $record,
        public ?int $expectedVersion = null,
        public bool $reasonGiven = false,
        public bool $payloadGiven = false,
        public array $payloadErrors = [],
        public bool $contextTooLarge = false,
        public array $storedContext = [],
        public bool $sweep = false,
    ) {}

    public function fromKey(): string
    {
        return $this->definition->key($this->context->from);
    }

    public function isSelfTransition(): bool
    {
        return $this->fromKey() === $this->context->transition->to;
    }
}
