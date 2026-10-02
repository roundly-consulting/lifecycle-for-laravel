<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Lifecycle\Tests\Support\SourceScan;
use RoundlyConsulting\Testing\Arch\ArchPresets;

/**
 * The seven arch presets every roundly package adopts, scoped to what a fresh package
 * actually has. Two are deliberately NOT registered here, for the same reason metrics and
 * enums skip them: this package ships no Eloquent model and no `*_model` config key, so
 * both would be green on the first run and stay green forever — vacuous rather than
 * adopted. Add them back the moment the package gains a swappable model:
 *
 *   - `swappableModelsAreNotFinal` — needs a `Model::class => 'handle.model'` map.
 *   - `modelsResolveThroughSeam` — needs a real model resolved through a Support seam.
 *
 * `morphColumnsUseTheSeam` is likewise skipped until the package ships migrations with
 * polymorphic columns.
 */
ArchPresets::strictTypes('RoundlyConsulting\Lifecycle');
// The manager is the one deliberate non-final class: LifecycleFake extends it, so an
// injected manager still type-checks under Lifecycles::fake().
ArchPresets::finalByDefault('RoundlyConsulting\Lifecycle', [LifecycleManager::class]);
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Lifecycle');

/**
 * The Dependency Policy as a test. No `alsoAllow`: this template's `require` ships only
 * php/illuminate/roundly. If this goes red the graph is wrong — never widen the allow-list
 * to quiet it.
 */
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../composer.json');

ArchPresets::noDebuggingLeftovers();

// Enable once the package has Models/Concerns/Traits — the preset fails on an empty namespace.
// ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Lifecycle');

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
