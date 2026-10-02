<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Lifecycle\Testing\LifecycleFake;

/**
 * @method static \RoundlyConsulting\Lifecycle\LifecycleHandle for(\Illuminate\Database\Eloquent\Model $subject, string|null $lifecycle = null)
 * @method static \RoundlyConsulting\Lifecycle\ModelLifecycle model(string $class, string|null $lifecycle = null)
 * @method static \RoundlyConsulting\Lifecycle\Accessors\DefinitionsAccessor definitions()
 * @method static \RoundlyConsulting\Lifecycle\Accessors\SchedulesAccessor schedules()
 * @method static \RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult apply(\RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest $request)
 * @method static \RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionAttempt attempt(\RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest $request)
 * @method static \RoundlyConsulting\Lifecycle\DataTransferObjects\Decision check(\RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest $request)
 * @method static array<int, \RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransition> available(\RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransitionsQuery $query)
 * @method static \RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackResult rollback(\RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackRequest $request)
 * @method static \RoundlyConsulting\Lifecycle\DataTransferObjects\Decision checkRollback(\RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackRequest $request)
 * @method static \RoundlyConsulting\Lifecycle\DataTransferObjects\PruneResult prune(\RoundlyConsulting\Lifecycle\DataTransferObjects\PruneOptions $options)
 * @method static bool freeze(\RoundlyConsulting\Lifecycle\DataTransferObjects\FreezeRequest $request)
 * @method static bool unfreeze(\RoundlyConsulting\Lifecycle\DataTransferObjects\UnfreezeRequest $request)
 * @method static \RoundlyConsulting\Lifecycle\DataTransferObjects\ScheduledTransition schedule(\RoundlyConsulting\Lifecycle\DataTransferObjects\ScheduleRequest $request)
 * @method static bool cancelScheduled(\RoundlyConsulting\Lifecycle\DataTransferObjects\CancelScheduleRequest $request)
 * @method static \Carbon\CarbonImmutable|null changeExpiry(\RoundlyConsulting\Lifecycle\DataTransferObjects\ExpiryChangeRequest $request)
 * @method static \RoundlyConsulting\Lifecycle\DataTransferObjects\SweepResult runDueSchedules(\RoundlyConsulting\Lifecycle\DataTransferObjects\SweepOptions $options)
 * @method static int sendExpiryWarnings(\RoundlyConsulting\Lifecycle\DataTransferObjects\SweepOptions $options)
 * @method static bool retrySchedule(int $scheduleId)
 * @method static \RoundlyConsulting\Lifecycle\DataTransferObjects\SweepResult sweep(int|null $limit = null, bool|null $queue = null)
 * @method static bool adopt(\Illuminate\Database\Eloquent\Model $subject, string|null $lifecycle = null)
 * @method static int adoptAll(string $class, string|null $lifecycle = null, int $chunk = 500, bool $scheduleExpiry = true)
 * @method static mixed allowDirectWrites(\Closure $callback)
 * @method static \RoundlyConsulting\Lifecycle\Testing\LifecycleFake denyNext(string $transition, \RoundlyConsulting\Lifecycle\Enums\DenialCode|string $code = \RoundlyConsulting\Lifecycle\Enums\DenialCode::GuardFailed, string|null $message = null)
 * @method static \RoundlyConsulting\Lifecycle\Testing\LifecycleFake deny(string $transition, \RoundlyConsulting\Lifecycle\Enums\DenialCode|string $code, string|null $message = null)
 * @method static array<int, \RoundlyConsulting\Lifecycle\Testing\RecordedCall> recorded()
 * @method static void assertTransitioned(\Illuminate\Database\Eloquent\Model $subject, string|null $transition = null, \Closure|null $callback = null)
 * @method static void assertTransitionedTo(\Illuminate\Database\Eloquent\Model $subject, \BackedEnum|string|int $state, string|null $lifecycle = null)
 * @method static void assertNotTransitioned(\Illuminate\Database\Eloquent\Model $subject, string|null $transition = null)
 * @method static void assertNothingTransitioned()
 * @method static void assertTransitionDenied(\Illuminate\Database\Eloquent\Model $subject, string|null $transition = null, \RoundlyConsulting\Lifecycle\Enums\DenialCode|string|null $code = null)
 * @method static void assertFrozen(\Illuminate\Database\Eloquent\Model $subject, string|null $lifecycle = null)
 * @method static void assertUnfrozen(\Illuminate\Database\Eloquent\Model $subject, string|null $lifecycle = null)
 * @method static void assertNothingFrozen()
 * @method static void assertRolledBack(\Illuminate\Database\Eloquent\Model $subject, \Closure|null $callback = null)
 * @method static void assertNothingRolledBack()
 * @method static void assertPruned()
 * @method static void assertNotPruned()
 * @method static void assertScheduled(\Illuminate\Database\Eloquent\Model $subject, string $transition, \Carbon\CarbonInterface|null $at = null)
 * @method static void assertNothingScheduled()
 * @method static void assertExpiryChanged(\Illuminate\Database\Eloquent\Model $subject, \RoundlyConsulting\Lifecycle\Enums\ExpiryChange|null $change = null)
 * @method static void assertNoExpiryChanged()
 * @method static void assertSwept(int|null $times = null)
 * @method static void assertNotSwept()
 * @method static void assertAdopted(\Illuminate\Database\Eloquent\Model|null $subject = null)
 *
 * @see LifecycleManager
 */
final class Lifecycles extends Facade
{
    /**
     * Swap the manager for the fake — behind the facade and in the container, so an injected
     * LifecycleManager is faked too.
     */
    public static function fake(): LifecycleFake
    {
        $fake = app(LifecycleFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return LifecycleManager::class;
    }
}
