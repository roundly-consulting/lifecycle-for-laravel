<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Support\SoftDeletion;
use RoundlyConsulting\Lifecycle\Support\SubjectResolver;

/**
 * Adopts every row of a model in chunks — for tables that existed before the lifecycle, or
 * after bulk writes that bypassed model events. Soft-deleted rows are skipped. Returns how
 * many subjects changed.
 */
final readonly class AdoptModelLifecyclesAction
{
    public function __construct(
        private DefinitionRegistry $registry,
        private AdoptLifecycleAction $adopt,
    ) {}

    public function execute(string $class, string $lifecycle, int $chunk = 500, bool $scheduleExpiry = true): int
    {
        $class = SubjectResolver::classFor($class);
        $model = new $class;
        $lifecycle = $this->registry->lifecycleName($model, $lifecycle);

        if ($chunk < 1) {
            throw InvalidLifecycleUsageException::invalidRequest('the adoption chunk size must be at least 1');
        }

        $query = $model->newQueryWithoutScopes();

        if (SoftDeletion::uses($model)) {
            $query->whereNull(SoftDeletion::qualifiedColumn($model));
        }

        $changed = 0;

        $query->chunkById($chunk, function (Collection $subjects) use ($lifecycle, $scheduleExpiry, &$changed): void {
            foreach ($subjects as $subject) {
                if ($this->adopt->execute($subject, $lifecycle, $scheduleExpiry)) {
                    $changed++;
                }
            }
        });

        return $changed;
    }
}
