<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransition;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRecord;
use RoundlyConsulting\Lifecycle\Http\Resources\TransitionRecordResource;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use Throwable;

/**
 * One subject's lifecycle at a glance: state, entry time, version, freeze, expiry, what the
 * system could do next, and the latest history.
 */
final class ShowCommand extends Command
{
    protected $signature = 'lifecycle:show
        {subject : Model class name or morph alias}
        {id : The subject key}
        {--lifecycle= : The lifecycle attribute (default: the first)}
        {--history=10 : How many history rows to show}';

    protected $description = 'Show the lifecycle of one subject';

    public function handle(LifecycleManager $lifecycle, ResolvesLifecycleArguments $arguments): int
    {
        try {
            $limit = IntegerOption::parse($this->option('history'), 'history', 0) ?? 10;
            $class = $arguments->model((string) $this->argument('subject'));
            $subject = (new $class)->newQueryWithoutScopes()->find($this->argument('id'));

            if ($subject === null) {
                $this->error(sprintf('No [%s] with key [%s].', $class, (string) $this->argument('id')));

                return self::FAILURE;
            }

            $option = $this->option('lifecycle');
            $handle = $lifecycle->for($subject, is_string($option) && $option !== '' ? $option : null)->asSystem();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $state = $handle->state();
        $definition = $handle->definition();

        $this->table(['', ''], [
            ['lifecycle', $handle->lifecycle],
            ['state', $definition->stateLabel($definition->key($state)).' ('.$definition->key($state).')'],
            ['entered at', $handle->enteredAt()?->toIso8601String() ?? '-'],
            ['version', (string) $handle->version()],
            ['frozen', $handle->isFrozen() ? 'until '.($handle->frozenUntil()?->toIso8601String() ?? 'unfrozen') : 'no'],
            ['expires at', $handle->expiresAt()?->toIso8601String() ?? '-'],
            ['system can', implode(', ', array_map(
                static fn (AvailableTransition $transition): string => $transition->name,
                $handle->allowedTransitions(),
            )) ?: '-'],
        ]);

        if ($limit > 0) {
            $this->table(['#', 'kind', 'transition', 'from', 'to', 'at', 'reverted'], $handle->history($limit)->map(
                static fn (TransitionRecord $record): array => [
                    $record->id,
                    $record->kind->value,
                    $record->transition ?? '-',
                    (string) (TransitionRecordResource::raw($record->from) ?? '-'),
                    (string) TransitionRecordResource::raw($record->to),
                    $record->occurredAt->toIso8601String(),
                    $record->reverted ? 'yes' : '',
                ],
            )->all());
        }

        return self::SUCCESS;
    }
}
