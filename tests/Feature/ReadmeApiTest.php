<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\StateBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\LifecycleHandle;
use RoundlyConsulting\Lifecycle\LifecycleManager;

/**
 * The README checked against the package. The README is slim — install, one example, a link to
 * the website docs — so it is checked for truth, not completeness: every PHP block parses, and
 * every method it calls exists on the package's public surface, so a rename cannot leave it
 * behind. Each check first proves it found something.
 */
function readmeText(): string
{
    return (string) file_get_contents(__DIR__.'/../../README.md');
}

/**
 * @return list<string>
 */
function readmePhpBlocks(): array
{
    preg_match_all('/```php\n(.*?)```/s', readmeText(), $matches);

    return $matches[1];
}

function phpLints(string $code): bool
{
    $file = tempnam(sys_get_temp_dir(), 'readme-lint').'.php';
    file_put_contents($file, $code);
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $status);
    unlink($file);

    return $status === 0;
}

it('parses every PHP block of the README', function (): void {
    $blocks = readmePhpBlocks();
    $broken = [];

    foreach ($blocks as $index => $block) {
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

    expect(count($blocks))->toBeGreaterThanOrEqual(3)
        ->and($broken)->toBe([]);
});

it('calls only methods the package has', function (): void {
    preg_match_all('/->([a-zA-Z]+)\(/', implode("\n", readmePhpBlocks()), $matches);
    $called = array_values(array_unique($matches[1]));
    $surface = [LifecycleBuilder::class, StateBuilder::class, TransitionBuilder::class, LifecycleHandle::class, LifecycleManager::class, HasLifecycle::class];

    $missing = array_values(array_filter($called, static function (string $method) use ($surface): bool {
        foreach ($surface as $class) {
            if (method_exists($class, $method)) {
                return false;
            }
        }

        return true;
    }));

    expect(count($called))->toBeGreaterThanOrEqual(15)
        ->and($missing)->toBe([]);
});
