<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\TransitionDefinition;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Builds what the pipeline evaluates: validates the payload (only validated keys survive),
 * measures what history would store, and assembles the context guards and handlers see.
 *
 * @internal
 */
final readonly class ContextFactory
{
    public function __construct(
        private Container $container,
    ) {}

    public function evaluation(
        CompiledDefinition $definition,
        TransitionRequest $request,
        TransitionDefinition $transition,
        string $fromKey,
        Mode $mode,
        ?LifecycleState $record,
        ?Model $actor,
        ?int $scheduleId = null,
        bool $sweep = false,
    ): Evaluation {
        $errors = [];
        $validated = [];

        if ($transition->rules !== []) {
            $validator = $this->container->make(ValidationFactory::class)
                ->make($request->payload, $transition->rules, $transition->messages);

            if ($validator->fails()) {
                $errors = array_map(
                    static fn (array $messages): array => array_values(array_map(strval(...), $messages)),
                    $validator->errors()->toArray(),
                );
            } else {
                $validated = $validator->validated();
            }
        }

        $stored = Arr::except($validated, $transition->sensitive);
        $limit = Config::using(InvalidLifecycleConfigurationException::class)
            ->intBetween('lifecycle.history.max_context_bytes', 1024, 1048576, 16384);

        return new Evaluation(
            definition: $definition,
            context: new TransitionContext(
                subject: $request->subject,
                lifecycle: $request->lifecycle,
                transition: $transition,
                from: $definition->value($fromKey),
                to: $definition->value($transition->to),
                actor: $actor,
                system: $request->system,
                reason: $request->reason,
                payload: $validated,
                now: Clock::now(),
                version: $record === null ? 0 : $record->version,
                scheduleId: $scheduleId,
            ),
            mode: $mode,
            record: $record,
            expectedVersion: $request->expectedVersion,
            reasonGiven: $request->reason !== null,
            payloadGiven: $request->payload !== [],
            payloadErrors: $errors,
            contextTooLarge: strlen((string) json_encode($stored)) > $limit,
            storedContext: $stored,
            sweep: $sweep,
        );
    }
}
