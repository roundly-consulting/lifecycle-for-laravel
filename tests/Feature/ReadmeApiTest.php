<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\StateBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\LifecycleHandle;
use RoundlyConsulting\Lifecycle\Testing\LifecycleFake;

/**
 * The README checked against the package: every PHP block parses, and every method the
 * README's API lists name exists on the class it is listed for — so a rename cannot leave the
 * documentation behind. Each check first proves it found something.
 */
function readmeText(): string
{
    return (string) file_get_contents(__DIR__.'/../../README.md');
}

function phpLints(string $code): bool
{
    $file = tempnam(sys_get_temp_dir(), 'readme-lint').'.php';
    file_put_contents($file, $code);
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $status);
    unlink($file);

    return $status === 0;
}

/**
 * The method names called in a text: `name(`.
 *
 * @return list<string>
 */
function calledMethods(string $text): array
{
    preg_match_all('/`([a-zA-Z]+)\(/', $text, $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * The method names in the first column of the table under a bold `Class` heading.
 *
 * @return list<string>
 */
function builderTableMethods(string $class): array
{
    $readme = readmeText();
    $start = strpos($readme, "**`{$class}`**");

    if ($start === false) {
        return [];
    }

    preg_match('/\n\n((?:\|.*\n)+)/', substr($readme, $start), $table);
    $methods = [];

    foreach (explode("\n", trim($table[1] ?? '')) as $row) {
        $first = explode(' | ', ltrim($row, '| '))[0];
        $methods = [...$methods, ...calledMethods($first)];
    }

    return array_values(array_unique($methods));
}

/**
 * The paragraph that starts with `$marker`, up to the next blank line.
 */
function readmeParagraph(string $marker): string
{
    $readme = readmeText();
    $start = strpos($readme, $marker);

    return $start === false ? '' : (string) strstr(substr($readme, $start)."\n\n", "\n\n", true);
}

it('parses every PHP block of the README', function (): void {
    preg_match_all('/```php\n(.*?)```/s', readmeText(), $matches);
    $broken = [];

    foreach ($matches[1] as $index => $block) {
        // Fragments: top-level code, a statement inside a function body, or a method body.
        $variants = ["<?php\n{$block}", "<?php\nfunction readme_fragment(): void {\n{$block}\n}", "<?php\nfinal class ReadmeFragment {\n{$block}\n}"];
        $parses = false;

        foreach ($variants as $variant) {
            if (phpLints($variant)) {
                $parses = true;

                break;
            }
        }

        if (! $parses) {
            $broken[] = $index.': '.strtok($block, "\n");
        }
    }

    expect(count($matches[1]))->toBeGreaterThan(30)
        ->and($broken)->toBe([]);
});

it('lists only builder methods that exist', function (string $class, int $minimum): void {
    $methods = builderTableMethods(class_basename($class));
    $missing = array_values(array_filter($methods, static fn (string $method): bool => ! method_exists($class, $method)));

    expect(count($methods))->toBeGreaterThanOrEqual($minimum)
        ->and($missing)->toBe([]);
})->with([
    'lifecycle builder' => [LifecycleBuilder::class, 7],
    'state builder' => [StateBuilder::class, 12],
    'transition builder' => [TransitionBuilder::class, 25],
]);

it('lists only handle methods that exist', function (): void {
    $methods = [
        ...calledMethods(readmeParagraph('Reads on the handle:')),
        ...calledMethods(readmeParagraph('Checks on the handle:')),
        ...calledMethods(readmeParagraph('Changes on the handle:')),
    ];
    $missing = array_values(array_filter($methods, static fn (string $method): bool => ! method_exists(LifecycleHandle::class, $method)));

    expect(count($methods))->toBeGreaterThanOrEqual(45)
        ->and($missing)->toBe([]);
});

it('lists only fake assertions and controls that exist', function (): void {
    $methods = calledMethods(readmeParagraph('`assertTransitioned('));
    $missing = array_values(array_filter($methods, static fn (string $method): bool => ! method_exists(LifecycleFake::class, $method)));

    expect(count($methods))->toBeGreaterThanOrEqual(30)
        ->and($missing)->toBe([]);
});

it('lists every assertion of the fake', function (): void {
    $listed = calledMethods(readmeParagraph('`assertTransitioned('));
    $asserts = array_values(array_filter(
        get_class_methods(LifecycleFake::class),
        static fn (string $method): bool => str_starts_with($method, 'assert'),
    ));

    expect($asserts)->toHaveCount(27)
        ->and(array_values(array_diff($asserts, $listed)))->toBe([]);
});
