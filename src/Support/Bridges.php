<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use RoundlyConsulting\Lifecycle\Bridges\Teams\TeamsSubjectContext;
use RoundlyConsulting\Lifecycle\Contracts\RestoresSubjectContext;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Whether an optional Roundly package is installed: its marker class exists (a string literal,
 * so nothing outside a bridge folder names that package's namespace). Stateless — the CI leg
 * without teams proves the not-installed side for real.
 *
 * @internal
 */
final class Bridges
{
    public const array MARKERS = [
        'teams' => 'RoundlyConsulting\\Teams\\TeamsManager',
    ];

    public static function available(string $package): bool
    {
        $marker = self::MARKERS[$package] ?? null;

        return $marker !== null && class_exists($marker);
    }

    /**
     * `lifecycle.subjects.context`, validated strictly: null = the teams bridge when
     * teams-for-laravel is installed, else none. Anything but an instantiable
     * RestoresSubjectContext throws.
     *
     * @return class-string<RestoresSubjectContext>
     */
    public static function subjectContext(): string
    {
        return Config::using(InvalidLifecycleConfigurationException::class)
            ->implementation('lifecycle.subjects.context', RestoresSubjectContext::class)
            ?? (self::available('teams') ? TeamsSubjectContext::class : NullSubjectContext::class);
    }
}
