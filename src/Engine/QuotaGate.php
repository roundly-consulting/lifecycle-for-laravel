<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\Constraints\QuotaRule;
use RoundlyConsulting\Lifecycle\Enums\DenialCode;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Exceptions\QuotaScopeException;
use RoundlyConsulting\Lifecycle\Models\QuotaLock;
use RoundlyConsulting\Lifecycle\Support\LockedRow;
use RoundlyConsulting\Lifecycle\Support\SoftDeletion;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;

/**
 * Row 18: race-free quotas. Under the subject lock, entering a quota'd state first takes the
 * partition's mutex row (quota name order), then counts the other subjects already in the
 * state. On pgsql (READ COMMITTED) and sqlite the count is a plain, later statement — its
 * snapshot includes the previous mutex holder's commit; on MySQL/MariaDB it is a locking
 * (current) read, since a REPEATABLE READ snapshot may predate that commit.
 *
 * @internal
 */
final readonly class QuotaGate
{
    /**
     * @return list<Denial>
     */
    public function check(Evaluation $evaluation, bool $lock): array
    {
        $context = $evaluation->context;

        return $this->entering(
            $context->subject,
            $context->lifecycle,
            $evaluation->definition,
            $evaluation->fromKey(),
            $context->transition->to,
            $context->transition->label(),
            $lock,
        );
    }

    /**
     * The quotas of `$to` for a subject entering it from `$from` (nothing when they are equal).
     *
     * @return list<Denial>
     */
    public function entering(Model $subject, string $lifecycle, CompiledDefinition $definition, string $from, string $to, string $transitionLabel, bool $lock): array
    {
        if ($from === $to) {
            return [];
        }

        $target = $definition->state($to);
        $quotas = $target->quotas;
        usort($quotas, static fn (QuotaRule $a, QuotaRule $b): int => strcmp($a->name, $b->name));
        $denials = [];

        foreach ($quotas as $quota) {
            $max = $this->max($quota, $subject);
            $params = ['max' => $max, 'current' => 0, 'state' => $target->label(), 'transition' => $transitionLabel];

            $values = [];

            foreach ($quota->scope as $column) {
                $values[$column] = $subject->getRawOriginal($column);
            }

            $key = json_encode(array_values($values), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            if (strlen($key) > 191) {
                throw QuotaScopeException::tooLong($quota->name);
            }

            if ($max === 0) {
                $denials[] = Denial::of(DenialCode::QuotaExceeded, $params, source: 'quota');

                continue;
            }

            $connection = $subject->getConnection();
            $locksCount = false;

            if ($lock) {
                LockedRow::mutex(QuotaLock::on($subject->getConnectionName()), [
                    'subject_type' => $subject->getMorphClass(),
                    'lifecycle' => $lifecycle,
                    'quota' => $quota->name,
                    'scope_key' => $key,
                ]);

                $locksCount = self::countTakesLocks(DatabaseDriver::tryFrom($connection->getDriverName()), $connection->transactionLevel());
            }

            $query = $this->partition($subject, $lifecycle, $definition->encode($target->key), $values);

            if ($locksCount) {
                $query->lockForUpdate();
            }

            // Keys, not COUNT(*): an aggregate under FOR UPDATE is refused by pgsql, and the
            // limit keeps the locked set at most `max` rows.
            $current = count($query->limit($max)->pluck($subject->getKeyName())->all());

            if ($current >= $max) {
                $denials[] = Denial::of(DenialCode::QuotaExceeded, [...$params, 'current' => min($current, $max)], source: 'quota');
            }
        }

        return $denials;
    }

    /**
     * A plain statement after the mutex reads the previous holder's commit on pgsql (READ
     * COMMITTED), sqlite (serialised writers) and MySQL/MariaDB inside a transaction the
     * package opened (switched to READ COMMITTED, see Support\Transactions). Nested in a host's
     * MySQL transaction (REPEATABLE READ: the snapshot may predate that commit), or on an
     * unknown engine, the count is a locking (current) read. Never an aggregate under FOR UPDATE.
     */
    public static function countTakesLocks(?DatabaseDriver $driver, int $transactionLevel): bool
    {
        return match ($driver) {
            DatabaseDriver::Pgsql, DatabaseDriver::Sqlite => false,
            DatabaseDriver::Mysql, DatabaseDriver::Mariadb => $transactionLevel > 1,
            null => true,
        };
    }

    private function max(QuotaRule $quota, Model $subject): int
    {
        $max = $quota->max instanceof Closure ? ($quota->max)($subject) : $quota->max;
        $max = is_int($max) ? $max : (int) (is_numeric($max) ? $max : -1);

        return $max < 0 ? throw InvalidLifecycleUsageException::invalidQuota($quota->name, $max) : $max;
    }

    /**
     * The other subjects in the target state and the same partition — global scopes off (a
     * tenant scope would make a console sweep count differently), soft-deleted rows out.
     *
     * @param  array<string, mixed>  $values
     * @return Builder<Model>
     */
    private function partition(Model $subject, string $lifecycle, string|int $state, array $values): Builder
    {
        $query = $subject->newQueryWithoutScopes()
            ->where($subject->qualifyColumn($lifecycle), $state)
            ->whereKeyNot($subject->getKey());

        foreach ($values as $column => $value) {
            $value === null
                ? $query->whereNull($subject->qualifyColumn($column))
                : $query->where($subject->qualifyColumn($column), $value);
        }

        if (SoftDeletion::uses($subject)) {
            $query->whereNull(SoftDeletion::qualifiedColumn($subject));
        }

        return $query;
    }
}
