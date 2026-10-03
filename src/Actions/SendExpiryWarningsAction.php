<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Lifecycle\DataTransferObjects\SweepOptions;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Engine\Warnings;
use RoundlyConsulting\Lifecycle\Enums\ScheduleKind;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Events\LifecycleExpiring;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownLifecycleException;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownStateException;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\Lifecycle\Support\ScheduleModel;
use RoundlyConsulting\Lifecycle\Support\SubjectResolver;
use RoundlyConsulting\Lifecycle\Support\Transactions;
use RoundlyConsulting\PackageToolkit\Support\Config;
use Throwable;

/**
 * Fires due expiry warnings, each lead at most once per schedule row: the row advances with a
 * compare-and-swap on `warnings_sent`, so two concurrent warners fire once. Returns how many
 * warnings fired. A row that throws never aborts the pass (the sweep runs the due schedules
 * after it): the exception is reported, and a row the current definitions cannot resolve (its
 * model is no longer a subject, its lifecycle or state was removed) stops warning.
 */
final readonly class SendExpiryWarningsAction
{
    public function __construct(
        private DefinitionRegistry $registry,
        private Dispatcher $events,
        private ExceptionHandler $exceptions,
    ) {}

    public function execute(SweepOptions $options): int
    {
        $config = Config::using(InvalidLifecycleConfigurationException::class);
        $limit = $options->limit ?? $config->intBetween('lifecycle.schedules.max_per_run', 1, 1000000, 10000);
        $batch = $config->intBetween('lifecycle.schedules.batch_size', 1, 10000, 500);
        $now = Clock::now();
        $fired = 0;
        $done = 0;
        $cursor = null;

        while ($done < $limit) {
            $query = ScheduleModel::query()
                ->where('status', ScheduleStatus::Pending->value)
                ->where('kind', ScheduleKind::Expiry->value)
                ->whereNotNull('next_warn_at')
                ->where('next_warn_at', '<=', Clock::format($now))
                ->orderBy('next_warn_at')
                ->orderBy('id')
                ->limit(min($batch, $limit - $done));

            if ($cursor !== null) {
                [$warnAt, $id] = $cursor;
                $query->where(static fn ($keyset) => $keyset->where('next_warn_at', '>', $warnAt)
                    ->orWhere(static fn ($tie) => $tie->where('next_warn_at', $warnAt)->where('id', '>', $id)));
            }

            $rows = $query->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $schedule) {
                $fired += $this->isolated($schedule);
            }

            $last = $rows->last();
            $cursor = [Clock::format($last->next_warn_at ?? $now), $last->id];
            $done += $rows->count();
        }

        return $fired;
    }

    private function isolated(LifecycleSchedule $schedule): int
    {
        try {
            return $this->warn($schedule);
        } catch (UnknownLifecycleException|UnknownStateException $exception) {
            $this->exceptions->report($exception);
            // Unresolvable for good: the row stops warning; the due pass cancels or fails it.
            $schedule->newQuery()->whereKey($schedule->id)->toBase()->update(['next_warn_at' => null]);
        } catch (Throwable $exception) {
            // Anything else (a listener, the database) may pass: the next sweep tries again.
            $this->exceptions->report($exception);
        }

        return 0;
    }

    private function warn(LifecycleSchedule $schedule): int
    {
        $now = Clock::now();
        $subject = SubjectResolver::find($schedule->subject_type, $schedule->subject_id);
        $expiresAt = $schedule->expires_at;

        if ($subject === null || $expiresAt === null) {
            $schedule->newQuery()->whereKey($schedule->id)->toBase()->update(['next_warn_at' => null]);

            return 0;
        }

        $definition = $this->registry->of($subject, $schedule->lifecycle);
        $leads = $definition->state($schedule->for_state)->ttl->leads ?? [];
        $step = Warnings::advance($leads, $expiresAt, $now, $schedule->warnings_sent);

        if ($step->fire === null) {
            $schedule->newQuery()->whereKey($schedule->id)->toBase()
                ->update(['next_warn_at' => $step->next === null ? null : Clock::format($step->next)]);

            return 0;
        }

        $lead = $step->fire;

        return Transactions::run($schedule, function () use ($schedule, $step, $subject, $definition, $expiresAt, $lead): int {
            // sent' > seen, so a matched row is always a changed row (MySQL counts changed rows).
            $affected = $schedule->newQuery()
                ->whereKey($schedule->id)
                ->where('warnings_sent', $schedule->warnings_sent)
                ->where('status', ScheduleStatus::Pending->value)
                ->toBase()
                ->update([
                    'warnings_sent' => $step->sent,
                    'next_warn_at' => $step->next === null ? null : Clock::format($step->next),
                ]);

            // A concurrent warner already advanced the row: it fired, this one must not.
            if ($affected === 1) {
                $this->events->dispatch(new LifecycleExpiring(
                    $subject,
                    $schedule->lifecycle,
                    $definition->value($schedule->for_state),
                    $expiresAt,
                    $lead,
                    $schedule->id,
                ));
            }

            return $affected === 1 ? 1 : 0;
        });
    }
}
