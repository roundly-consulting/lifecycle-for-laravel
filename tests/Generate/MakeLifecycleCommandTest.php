<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\LifecycleServiceProvider;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\EmptyStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Support\GeneratorSandboxTestCase;

enum MakeLifecyclePureStatus
{
    case Draft;
}

/**
 * @return array{0: int, 1: string}
 */
function makeLifecycle(array $parameters): array
{
    $code = Artisan::call('make:lifecycle', $parameters);

    return [$code, Artisan::output()];
}

/**
 * Generated classes and published stubs land in a per-test mirror of the skeleton
 * ({@see GeneratorSandboxTestCase}), never the testbench skeleton the parallel suite boots from.
 */
it('generates and publishes into the sandbox, never the shared skeleton', function (): void {
    expect(app_path('Lifecycles'))->toContain('lifecycle-generate-')
        ->and(base_path('stubs'))->toContain('lifecycle-generate-')
        ->and(array_values(ServiceProvider::pathsToPublish(LifecycleServiceProvider::class, 'lifecycle-stubs')))->toBe([
            base_path('stubs/lifecycle.stub'),
            base_path('stubs/lifecycle.enum.stub'),
        ]);
});

it('generates a valid example definition', function (): void {
    [$code, $output] = makeLifecycle(['name' => 'GeneratedExampleLifecycle']);
    $path = app_path('Lifecycles/GeneratedExampleLifecycle.php');

    expect($code)->toBe(0)
        ->and($output)->toContain("Add it to your model's lifecycleDefinitions() and to lifecycle.subjects, then run php artisan lifecycle:validate.")
        ->and(File::get($path))->toContain('namespace App\Lifecycles;')
        ->toContain('final class GeneratedExampleLifecycle extends LifecycleDefinition');

    require_once $path;
    $report = app(DefinitionRegistry::class)->validate('App\Lifecycles\GeneratedExampleLifecycle');

    expect($report->errors())->toBe([])
        ->and($report->warnings())->toBe([])
        ->and(app(DefinitionRegistry::class)->get('App\Lifecycles\GeneratedExampleLifecycle')->initial)->toBe('draft');
});

it('generates a definition over the cases of a backed enum', function (): void {
    [$code] = makeLifecycle(['name' => 'GeneratedEnumLifecycle', '--enum' => '\\'.ListingStatus::class]);
    $path = app_path('Lifecycles/GeneratedEnumLifecycle.php');

    expect($code)->toBe(0)
        ->and(File::get($path))->toContain('use '.ListingStatus::class.';')
        ->toContain('->states(ListingStatus::class)')
        ->toContain('->initial(ListingStatus::Draft);');

    require_once $path;

    expect(app(DefinitionRegistry::class)->validate('App\Lifecycles\GeneratedEnumLifecycle')->errors())->toBe([]);
});

it('refuses an unusable enum', function (string $enum, string $message): void {
    [$code, $output] = makeLifecycle(['name' => 'RefusedLifecycle', '--enum' => $enum]);

    expect($code)->toBe(1)
        ->and($output)->toContain($message)
        ->and(File::exists(app_path('Lifecycles/RefusedLifecycle.php')))->toBeFalse();
})->with([
    'unknown' => ['App\Enums\Missing', 'The enum [App\Enums\Missing] does not exist or is not a backed enum.'],
    'not backed' => [MakeLifecyclePureStatus::class, 'is not a backed enum'],
    'not an enum' => [stdClass::class, 'is not a backed enum'],
    'without cases' => [EmptyStatus::class, 'has no cases'],
]);

it('refuses to overwrite an existing class unless forced', function (): void {
    makeLifecycle(['name' => 'ExistingLifecycle']);
    $path = app_path('Lifecycles/ExistingLifecycle.php');
    File::put($path, '<?php // edited');

    [, $output] = makeLifecycle(['name' => 'ExistingLifecycle']);

    expect($output)->toContain('already exists')
        ->and($output)->not->toContain('lifecycleDefinitions()')
        ->and(File::get($path))->toBe('<?php // edited');

    [$code] = makeLifecycle(['name' => 'ExistingLifecycle', '--force' => true]);

    expect($code)->toBe(0)
        ->and(File::get($path))->toContain('final class ExistingLifecycle extends LifecycleDefinition');
});

it('prefers a published stub', function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'lifecycle-stubs']);

    expect(File::exists(base_path('stubs/lifecycle.stub')))->toBeTrue()
        ->and(File::exists(base_path('stubs/lifecycle.enum.stub')))->toBeTrue();

    File::put(base_path('stubs/lifecycle.stub'), "<?php\n\n// house style\nnamespace {{ namespace }};\n\nfinal class {{ class }} {}\n");
    makeLifecycle(['name' => 'HouseStyleLifecycle']);

    expect(File::get(app_path('Lifecycles/HouseStyleLifecycle.php')))->toContain('// house style');
});
