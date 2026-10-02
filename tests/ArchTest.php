<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Support\SourceScan;
use RoundlyConsulting\Testing\Arch\ArchPresets;

/**
 * Every arch preset this package's shape qualifies for — models, swap seams, morph
 * columns, a manager the fake extends.
 */
ArchPresets::strictTypes('RoundlyConsulting\Lifecycle');
// The manager is non-final because LifecycleFake extends it; the three swappable models are
// non-final because hosts extend them (pinned by swappableModelsAreNotFinal below).
ArchPresets::finalByDefault('RoundlyConsulting\Lifecycle', [
    LifecycleManager::class,
    LifecycleState::class,
    LifecycleTransition::class,
    LifecycleSchedule::class,
]);
ArchPresets::swappableModelsAreNotFinal([
    LifecycleState::class => 'lifecycle.models.state',
    LifecycleTransition::class => 'lifecycle.models.transition',
    LifecycleSchedule::class => 'lifecycle.models.schedule',
]);
// Rate-limit and quota keys are json_encode()d, never hashed.
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Lifecycle');
ArchPresets::modelsResolveThroughSeam(__DIR__.'/../src', 'Support', [
    'lifecycle.models.state',
    'lifecycle.models.transition',
    'lifecycle.models.schedule',
]);
ArchPresets::morphColumnsUseTheSeam(__DIR__.'/../database/migrations');

/**
 * The Dependency Policy as a test. No `alsoAllow`: `require` ships only php, illuminate and
 * roundly packages. If this goes red the graph is wrong — never widen the allow-list.
 */
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../composer.json');

ArchPresets::noDebuggingLeftovers();

// The trait and the models reach behaviour through LifecycleManager, never an action.
ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Lifecycle');

/**
 * Bespoke rules of this package. Each first proves it scanned something, so an empty or
 * moved directory cannot pass vacuously.
 */
it('reads the time only through Support\Clock', function (): void {
    $files = SourceScan::files(__DIR__.'/../src');
    $offenders = array_values(array_filter(
        $files,
        static fn (string $file): bool => ! str_ends_with($file, '/Support/Clock.php') && SourceScan::readsTheClock($file),
    ));

    expect(count($files))->toBeGreaterThan(20)
        ->and(SourceScan::readsTheClock(__DIR__.'/../src/Support/Clock.php'))->toBeTrue()
        ->and($offenders)->toBe([]);
});

it('keeps no static properties or static locals anywhere in src', function (): void {
    $files = SourceScan::files(__DIR__.'/../src');

    expect(count($files))->toBeGreaterThan(20)
        ->and(array_values(array_filter($files, SourceScan::declaresStaticState(...))))->toBe([]);
});

it('keeps the definition model and the graph renderers free of database I/O', function (): void {
    $files = [...SourceScan::files(__DIR__.'/../src/Definition'), ...SourceScan::files(__DIR__.'/../src/Graph')];
    $offenders = [];

    foreach ($files as $file) {
        foreach (SourceScan::references($file) as $name) {
            if (str_starts_with($name, 'Illuminate\\Database\\') && $name !== 'Illuminate\\Database\\Eloquent\\Model') {
                $offenders[] = basename($file).': '.$name;
            }
        }
    }

    expect(count($files))->toBeGreaterThan(10)
        ->and($offenders)->toBe([]);
});

it('never touches the DB facade or Illuminate\Foundation', function (): void {
    $files = SourceScan::files(__DIR__.'/../src');
    $offenders = [];

    foreach ($files as $file) {
        foreach (SourceScan::references($file) as $name) {
            if ($name === 'Illuminate\\Support\\Facades\\DB' || str_starts_with($name, 'Illuminate\\Foundation\\')) {
                $offenders[] = basename($file).': '.$name;
            }
        }
    }

    expect(count($files))->toBeGreaterThan(20)
        ->and($offenders)->toBe([]);
});

it('catches what the bespoke scans are meant to catch', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'scan').'.php';
    file_put_contents($file, '<?php class X { public static ?int $memo = null; function f() { static $cache; return now(); } function g() { return \Carbon\Carbon::now(); } }');

    expect(SourceScan::declaresStaticState($file))->toBeTrue()
        ->and(SourceScan::readsTheClock($file))->toBeTrue();

    file_put_contents($file, '<?php /* now() */ class Y { public static function make(): static { return new static; } function f() { $fn = static fn () => $this->now(); return Clock::now(); } }');

    expect(SourceScan::declaresStaticState($file))->toBeFalse()
        ->and(SourceScan::readsTheClock($file))->toBeFalse();

    unlink($file);
});

it('marks every engine class @internal', function (): void {
    $files = SourceScan::files(__DIR__.'/../src/Engine');
    $public = array_values(array_filter(
        $files,
        static fn (string $file): bool => preg_match('/^\s*\*\s*@internal\b/m', (string) file_get_contents($file)) !== 1,
    ));

    expect(count($files))->toBeGreaterThan(10)
        ->and($public)->toBe([]);
});
