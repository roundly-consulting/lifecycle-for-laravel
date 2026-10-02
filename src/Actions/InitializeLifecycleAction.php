<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Engine\StateRecords;
use RoundlyConsulting\Lifecycle\Support\Transactions;

/**
 * Runs from the `created` model event: a state record, an `initial` history row and the
 * expiry of the starting state, for every lifecycle — inside the host's transaction when one
 * is open.
 *
 * @internal
 */
final readonly class InitializeLifecycleAction
{
    public function __construct(
        private DefinitionRegistry $registry,
        private StateRecords $records,
    ) {}

    public function execute(Model $subject): void
    {
        Transactions::run($subject, function () use ($subject): void {
            foreach (array_keys($this->registry->definitionsOf($subject)) as $lifecycle) {
                $this->records->initialize($subject, (string) $lifecycle, $this->registry->of($subject, (string) $lifecycle));
            }
        });
    }
}
