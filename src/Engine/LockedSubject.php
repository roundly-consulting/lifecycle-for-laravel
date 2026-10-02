<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectNotPersistedException;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Support\Transactions;
use Throwable;

/**
 * The shared frame of every mutation that is not a transition (freeze, schedules, expiry
 * changes): one transaction on the subject's connection, the subject row locked first, the
 * record reconciled, the in-memory model restored on failure.
 *
 * @internal
 */
final readonly class LockedSubject
{
    public function __construct(
        private DefinitionRegistry $registry,
        private SubjectLocker $locker,
        private StateRecords $records,
    ) {}

    /**
     * @template TReturn
     *
     * @param  Closure(LifecycleState, CompiledDefinition): TReturn  $callback
     * @return TReturn
     */
    public function run(Model $subject, string $lifecycle, Closure $callback, bool $allowTrashed = false): mixed
    {
        if (! $subject->exists) {
            throw SubjectNotPersistedException::for($subject);
        }

        $definition = $this->registry->of($subject, $lifecycle);
        $restore = RestorePoint::capture($subject);

        try {
            $result = Transactions::run($subject, function () use ($subject, $lifecycle, $definition, $restore, $callback, $allowTrashed): mixed {
                $restore->restore();
                $this->locker->lock($subject, $allowTrashed);

                return $callback($this->records->lock($subject, $lifecycle, $definition)->record, $definition);
            });
        } catch (Throwable $exception) {
            $restore->restore();

            throw $exception;
        }

        RestorePoint::forgetRelations($subject);

        return $result;
    }
}
