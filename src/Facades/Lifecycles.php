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
 * @method static \RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult apply(\RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest $request)
 * @method static \RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionAttempt attempt(\RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest $request)
 * @method static \RoundlyConsulting\Lifecycle\DataTransferObjects\Decision check(\RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest $request)
 * @method static array<int, \RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransition> available(\RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransitionsQuery $query)
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
