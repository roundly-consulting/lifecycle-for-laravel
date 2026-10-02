<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\PackageTemplate\Actions\ExamplePackageTemplateAction;
use RoundlyConsulting\PackageTemplate\DataTransferObjects\ExamplePackageTemplateData;
use RoundlyConsulting\PackageTemplate\Facades\PackageTemplate;
use RoundlyConsulting\PackageTemplate\PackageTemplateManager;
use RoundlyConsulting\PackageTemplate\Testing\PackageTemplateFake;

/**
 * The public API contract — Actions → Manager → Facade (+ fake) — pinned from day one.
 * Grow these tests with the API: every facade and sub-accessor method through the facade,
 * one DI resolution of the manager, cross-scope refusals, and a passing plus a failing case
 * for every fake assert.
 */
it('pins the facade contract', function (): void {
    expect(PackageTemplate::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

it('runs the example through the facade', function (): void {
    expect(PackageTemplate::example(new ExamplePackageTemplateData('Ada')))->toBe('Hello, Ada!');
});

it('runs the example through an injected manager', function (): void {
    $manager = app(PackageTemplateManager::class);

    expect($manager)->toBe(app(PackageTemplateManager::class))
        ->and($manager->example(new ExamplePackageTemplateData('Ada')))->toBe('Hello, Ada!');
});

it('runs the example action directly', function (): void {
    expect(app(ExamplePackageTemplateAction::class)->execute(new ExamplePackageTemplateData('Ada')))
        ->toBe('Hello, Ada!');
});

it('resolves the action through the container so a host override applies', function (): void {
    app()->bind(ExamplePackageTemplateAction::class, static fn (): never => throw new RuntimeException('overridden'));

    PackageTemplate::example(new ExamplePackageTemplateData('Ada'));
})->throws(RuntimeException::class, 'overridden');

it('records facade and injected calls under the fake without running the action', function (): void {
    app()->bind(ExamplePackageTemplateAction::class, static fn (): never => throw new RuntimeException('ran'));

    $fake = PackageTemplate::fake();

    PackageTemplate::example(new ExamplePackageTemplateData('Ada'));
    app(PackageTemplateManager::class)->example(new ExamplePackageTemplateData('Grace'));

    expect(app(PackageTemplateManager::class))->toBeInstanceOf(PackageTemplateFake::class);

    $fake->assertExampleCalled();
    $fake->assertExampleCalled(static fn (ExamplePackageTemplateData $data): bool => $data->name === 'Ada');
    PackageTemplate::assertExampleCalled(static fn (ExamplePackageTemplateData $data): bool => $data->name === 'Grace');
});

it('fails assertExampleCalled when nothing was called', function (): void {
    PackageTemplate::fake()->assertExampleCalled();
})->throws(ExpectationFailedException::class, 'Expected example() to be called, but it was not.');

it('fails assertExampleCalled when no call matches', function (): void {
    $fake = PackageTemplate::fake();

    PackageTemplate::example(new ExamplePackageTemplateData('Ada'));

    $fake->assertExampleCalled(static fn (ExamplePackageTemplateData $data): bool => $data->name === 'Grace');
})->throws(ExpectationFailedException::class, 'no call matched');

it('passes assertNothingCalled on an untouched fake', function (): void {
    PackageTemplate::fake()->assertNothingCalled();
});

it('fails assertNothingCalled after a call', function (): void {
    $fake = PackageTemplate::fake();

    PackageTemplate::example(new ExamplePackageTemplateData('Ada'));

    $fake->assertNothingCalled();
})->throws(ExpectationFailedException::class, 'example() was called 1 time(s)');

it('declares the global alias for the facade and never a core facade name', function (): void {
    /** @var array{extra: array{laravel: array{aliases: array<string, string>}}} $composer */
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    $aliases = $composer['extra']['laravel']['aliases'];
    $alias = (string) array_key_first($aliases);

    // Both halves: a facade class Laravel ships, and a global alias it registers without one
    // there (`Str`, `Number`, `Js`, …). Compared case-insensitively, as PHP resolves class names.
    $coreAliases = array_map(strtolower(...), array_keys(Facade::defaultAliases()->all()));

    expect($aliases)->toBe([class_basename(PackageTemplate::class) => PackageTemplate::class])
        ->and(class_exists('Illuminate\\Support\\Facades\\'.$alias))->toBeFalse()
        ->and($coreAliases)->not->toContain(strtolower($alias));
});
