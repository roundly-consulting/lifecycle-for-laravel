<?php

declare(strict_types=1);

namespace RoundlyConsulting\PackageTemplate\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\PackageTemplate\DataTransferObjects\ExamplePackageTemplateData;
use RoundlyConsulting\PackageTemplate\PackageTemplateManager;
use RoundlyConsulting\PackageTemplate\Testing\PackageTemplateFake;

/**
 * Keep one `@method static` line per public manager method — FacadeTest's
 * `toDocumentItsRoot()` fails on a missing, stale or miscounted line.
 *
 * @method static string example(ExamplePackageTemplateData $data)
 * @method static void assertExampleCalled(\Closure|null $callback = null)
 * @method static void assertNothingCalled()
 *
 * @see PackageTemplateManager
 */
final class PackageTemplate extends Facade
{
    /**
     * Swap the manager for a recording fake — behind the facade and in the container, so an
     * injected PackageTemplateManager is faked too.
     */
    public static function fake(): PackageTemplateFake
    {
        $fake = app(PackageTemplateFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return PackageTemplateManager::class;
    }
}
