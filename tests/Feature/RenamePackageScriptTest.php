<?php

declare(strict_types=1);
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;

/**
 * `bin/rename-package.sh` is the one piece of this repository that cannot be proven by
 * reading it: it is shell, it shells out to `sed` and `git mv`, and the two seds disagree
 * about `-i`. The BSD form `sed -i ''` was shipped here for real — GNU sed reads the empty
 * argument as a filename and exits 2, and under `set -euo pipefail` that aborted the run on
 * the first file, leaving a half-renamed tree. Every agent run and CI runner is Linux, so
 * the template could not rename a package at all while a comment claimed it could.
 *
 * These tests therefore RUN the script against a throwaway copy of the real tree and assert
 * on the result. A grep for the offending string would pin the one spelling we happen to
 * know about; executing it pins the behaviour, on whichever sed the runner actually has.
 *
 * They also pin the acceptance criterion a scaffolded package is checked against — no
 * PackageTemplate/package-template/PACKAGE_TEMPLATE anywhere, in ANY file type. That is
 * why the script deletes itself and this file at the end: the script's own substitution
 * patterns are the one place its rewrite cannot reach.
 *
 * This file only ever runs in the template. In a renamed package it is gone, along with
 * the script it tests.
 */

/**
 * Copy every tracked file into a scratch git repository and return its path.
 *
 * Tracked files only — `git ls-files` skips vendor/ and node_modules/, which the script
 * excludes anyway and which would make the copy enormous. The copy is `git init`ed and
 * committed because the script finishes with `git mv` and `git rm` calls: `git mv` refuses
 * a path that is not in the index, and `git rm` behaves differently against a repository
 * with no HEAD than against the fresh clone a real user runs this in.
 */
function scratchTemplate(): string
{
    $source = dirname(__DIR__, 2);
    $target = sys_get_temp_dir().'/rename-package-'.bin2hex(random_bytes(6));

    mkdir($target, 0o777, true);

    exec(sprintf('git -C %s ls-files -z', escapeshellarg($source)), $_, $listed);
    expect($listed)->toBe(0, 'could not list the tracked files of the template');

    $files = array_filter(explode("\0", (string) shell_exec(
        sprintf('git -C %s ls-files -z', escapeshellarg($source))
    )));

    foreach ($files as $file) {
        $destination = $target.'/'.$file;

        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0o777, true);
        }

        copy($source.'/'.$file, $destination);
    }

    // The identity is passed with -c rather than assumed: a CI runner has no global one.
    exec(sprintf(
        'git -C %1$s init -q && git -C %1$s add -A && '
        .'git -C %1$s -c user.name=Test -c user.email=test@example.com -c commit.gpgsign=false '
        .'commit -qm scaffold 2>&1',
        escapeshellarg($target)
    ), $_, $initialised);
    expect($initialised)->toBe(0, 'could not initialise the scratch repository');

    return $target;
}

/**
 * Run the rename script inside the scratch copy.
 *
 * @param  string|null  $name  the optional second argument: the public API name
 * @param  string|null  $prependPath  a directory put in front of PATH, to shadow a tool
 * @return array{0: int, 1: string} exit code and combined output
 */
function runRename(string $directory, string $domain, ?string $name = null, ?string $prependPath = null): array
{
    exec(sprintf(
        '%sbash %s %s%s 2>&1',
        $prependPath === null ? '' : 'PATH='.escapeshellarg($prependPath).':"$PATH" ',
        escapeshellarg($directory.'/bin/rename-package.sh'),
        escapeshellarg($domain),
        $name === null ? '' : ' '.escapeshellarg($name),
    ), $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}

/**
 * A `grep` that accepts only the POSIX options and rejects everything else with exit 2.
 *
 * CI is Linux, so GNU grep there would happily run a GNU-only flag the script must not use.
 * `grep -rlZ` shipped exactly like that: on macOS BSD grep -Z means "decompress" (ugrep
 * differs again), the NUL-separated file list came back empty, and the rename rewrote
 * nothing while exiting 0. Shadowing grep with this shim makes any non-POSIX flag fail the
 * run on every runner, not just on the machines where it silently misbehaves.
 *
 * @return string a directory to prepend to PATH
 */
function posixOnlyGrep(): string
{
    $real = trim((string) shell_exec('command -v grep'));
    expect($real)->not->toBe('', 'no grep on this runner');

    $bin = sys_get_temp_dir().'/posix-grep-'.bin2hex(random_bytes(6));
    mkdir($bin, 0o777, true);

    file_put_contents($bin.'/grep', <<<SH
        #!/usr/bin/env bash
        for arg in "\$@"; do
            [[ "\$arg" == -- ]] && break
            if [[ "\$arg" == -* && ! "\$arg" =~ ^-[EFcefilnqsvx]+\$ ]]; then
                echo "non-POSIX grep option: \$arg" >&2
                exit 2
            fi
        done
        exec {$real} "\$@"
        SH);
    chmod($bin.'/grep', 0o755);

    return $bin;
}

/**
 * Files still carrying a template token — every file type, not the script's own filter, and
 * in any casing.
 *
 * This is deliberately the acceptance grep a scaffolded package is judged by, verbatim.
 * Filtering by the script's --include list would grade the rewrite against its own opinion
 * of which files matter and would have called a package clean while `bin/rename-package.sh`
 * still sat in it full of tokens. Case-insensitive because a case-sensitive list only finds
 * the casings somebody thought of: the README's camelCase `$packageTemplate` matched none of
 * `PackageTemplate|package-template|PACKAGE_TEMPLATE` and survived every rename.
 */
function leftoverTemplateTokens(string $directory): string
{
    return (string) shell_exec(sprintf(
        'grep -rliE %s --exclude-dir=.git --exclude-dir=vendor %s 2>/dev/null',
        escapeshellarg('package[-_]?template'),
        escapeshellarg($directory)
    ));
}

/**
 * Paths whose NAME still carries a template token — the half the content grep above cannot see.
 *
 * The script once renamed a hand-kept list of two files; every token-named file added after
 * that (manager, facade, fake, example action) would have kept `PackageTemplate` in its name
 * while its contents said `Credits`, and a content grep calls that tree clean.
 */
function leftoverTemplateNames(string $directory): string
{
    return (string) shell_exec(sprintf(
        'find %s \\( -name .git -o -name vendor \\) -prune -o '
        .'\\( -iname %s -o -iname %s -o -iname %s \\) -print 2>/dev/null',
        escapeshellarg($directory),
        escapeshellarg('*packagetemplate*'),
        escapeshellarg('*package-template*'),
        escapeshellarg('*package_template*'),
    ));
}

/**
 * Load every class under the renamed `src/` and drive the renamed public API, in a separate
 * PHP process with the template's own vendor autoloader plus a PSR-4 loader for the new
 * namespace.
 *
 * A file that was renamed without its class (or the reverse) fails `class_exists`, so this is
 * the PSR-4 consistency check; calling the facade, the injected manager and the fake proves
 * the Actions -> Manager -> Facade scaffold still hangs together under its new name.
 *
 * @return array{0: int, 1: string} exit code and combined output
 */
function smokeRenamedApi(string $directory, string $domain, ?string $name = null): array
{
    $script = sys_get_temp_dir().'/rename-smoke-'.bin2hex(random_bytes(6)).'.php';

    file_put_contents($script, <<<'PHP'
        <?php

        declare(strict_types=1);

        [, $autoload, $src, $domain, $name] = $argv;

        require $autoload;

        $prefix = 'RoundlyConsulting\\'.$domain.'\\';

        spl_autoload_register(static function (string $class) use ($prefix, $src): void {
            $file = $src.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            if (str_starts_with($class, $prefix) && is_file($file)) {
                require $file;
            }
        });

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = $prefix.str_replace('/', '\\', substr($file->getPathname(), strlen($src) + 1, -4));

            if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class) && ! enum_exists($class)) {
                fwrite(STDERR, $file->getPathname().' does not declare '.$class.PHP_EOL);
                exit(1);
            }
        }

        $app = new Illuminate\Container\Container;
        Illuminate\Container\Container::setInstance($app);
        $app->instance(Illuminate\Contracts\Container\Container::class, $app);
        Illuminate\Support\Facades\Facade::setFacadeApplication($app);

        $facade = $prefix.'Facades\\'.$name;
        $manager = $prefix.$name.'Manager';
        $data = $prefix.'DataTransferObjects\\Example'.$name.'Data';

        $app->singleton($manager);

        echo $facade::example(new $data('Ada')), PHP_EOL;
        echo $app->make($manager)->example(new $data('Grace')), PHP_EOL;

        $fake = $facade::fake();
        $app->make($manager)->example(new $data('Linus'));
        $fake->assertExampleCalled(static fn (object $called): bool => $called->name === 'Linus');

        echo get_class($app->make($manager)), PHP_EOL;
        PHP);

    exec(sprintf(
        '%s %s %s %s %s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($script),
        escapeshellarg(dirname(__DIR__, 2).'/vendor/autoload.php'),
        escapeshellarg($directory.'/src'),
        escapeshellarg($domain),
        escapeshellarg($name ?? $domain),
    ), $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}

/** Paths the run staged as deletions. */
function stagedDeletions(string $directory): string
{
    return (string) shell_exec(sprintf(
        'git -C %s diff --cached --name-only --diff-filter=D',
        escapeshellarg($directory)
    ));
}

it('renames the whole template on this runner\'s sed', function (): void {
    $directory = scratchTemplate();

    [$exitCode, $output] = runRename($directory, 'Inspire');

    // The regression that shipped: GNU sed exits 2 on `sed -i ''` and pipefail kills the run.
    expect($exitCode)->toBe(0, "the rename script failed:\n".$output);

    // Every file type, so this is the same check a scaffolded package is accepted against.
    expect(leftoverTemplateTokens($directory))
        ->toBe('', 'the rename left template tokens behind')
        ->and(leftoverTemplateNames($directory))
        ->toBe('', 'the rename left template tokens in file names');
});

it('renames the whole template with only POSIX grep options', function (): void {
    $directory = scratchTemplate();

    [$exitCode, $output] = runRename($directory, 'Inspire', prependPath: posixOnlyGrep());

    expect($exitCode)->toBe(0, "the rename script needs a non-POSIX grep:\n".$output)
        ->and($output)->not->toContain('non-POSIX grep option');

    expect(leftoverTemplateTokens($directory))
        ->toBe('', 'the rename left template tokens behind');
});

it('fails loudly instead of exiting 0 over a half-renamed tree', function (): void {
    $directory = scratchTemplate();

    // A file type outside the rewrite's filter: the rename cannot reach it, so the run must
    // not report success. The macOS regression exited 0 with every token still in place.
    file_put_contents($directory.'/notes.txt', "PackageTemplate\n");

    [$exitCode, $output] = runRename($directory, 'Inspire');

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Rename incomplete')
        ->toContain('notes.txt');
});

it('deletes the template scaffolding that no rewrite could clean', function (): void {
    $directory = scratchTemplate();

    [$exitCode, $output] = runRename($directory, 'Inspire');
    expect($exitCode)->toBe(0, "the rename script failed:\n".$output);

    // The script's substitution patterns are themselves template tokens, so it cannot
    // rewrite itself out of the tree — it has to leave it. The self-test goes with it:
    // it tests a script the package no longer has.
    expect($directory.'/bin/rename-package.sh')->not->toBeFile()
        ->and($directory.'/tests/Feature/RenamePackageScriptTest.php')->not->toBeFile();

    // Staged, not merely unlinked, so the deletions travel with the rename commit.
    expect(stagedDeletions($directory))
        ->toContain('bin/rename-package.sh')
        ->toContain('tests/Feature/RenamePackageScriptTest.php');

    // Deleting the script mid-run must not truncate it: the closing instructions are
    // printed after the `git rm`, so seeing them proves bash read on past its own removal.
    expect($output)->toContain('Remaining manual steps');
});

it('renames every file that carries the name in its filename', function (): void {
    $directory = scratchTemplate();

    [$exitCode, $output] = runRename($directory, 'Credits');
    expect($exitCode)->toBe(0, "the rename script failed:\n".$output);

    expect($directory.'/src/CreditsServiceProvider.php')->toBeFile()
        ->and($directory.'/config/credits.php')->toBeFile()
        ->and($directory.'/src/CreditsManager.php')->toBeFile()
        ->and($directory.'/src/Facades/Credits.php')->toBeFile()
        ->and($directory.'/src/Testing/CreditsFake.php')->toBeFile()
        ->and($directory.'/src/Actions/ExampleCreditsAction.php')->toBeFile()
        ->and($directory.'/src/DataTransferObjects/ExampleCreditsData.php')->toBeFile()
        ->and($directory.'/src/PackageTemplateServiceProvider.php')->not->toBeFile()
        ->and($directory.'/config/package-template.php')->not->toBeFile();

    expect(leftoverTemplateNames($directory))->toBe('');

    // Staged as renames, so the history of each file follows it into the new package.
    expect((string) shell_exec(sprintf('git -C %s diff --cached --name-status -M', escapeshellarg($directory))))
        ->toMatch('#^R\d*\tsrc/Facades/PackageTemplate\.php\tsrc/Facades/Credits\.php$#m')
        ->toMatch('#^R\d*\tsrc/PackageTemplateManager\.php\tsrc/CreditsManager\.php$#m');
});

it('leaves a tree Pint accepts, whatever the domain sorts like', function (string $domain): void {
    $directory = scratchTemplate();

    [$exitCode, $output] = runRename($directory, $domain);
    expect($exitCode)->toBe(0, "the rename script failed:\n".$output);

    // Renaming moves `use` lines relative to each other (`CreditsManager` sorts before
    // `DataTransferObjects\…`, `PackageTemplateManager` after it); the code-style workflow runs
    // `pint --test`, so the renamed tree must already be in Pint's order.
    exec(sprintf(
        '%s %s --test --config=%s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(dirname(__DIR__, 2).'/vendor/bin/pint'),
        escapeshellarg($directory.'/pint.json'),
        escapeshellarg($directory),
    ), $pint, $pintExit);

    expect($pintExit)->toBe(0, "Pint rejects the renamed tree:\n".implode("\n", $pint));
})->with(['Credits', 'Zebra', 'Alpha']);

it('leaves a renamed public API that loads and runs', function (): void {
    $directory = scratchTemplate();

    [$exitCode, $output] = runRename($directory, 'Credits');
    expect($exitCode)->toBe(0, "the rename script failed:\n".$output);

    expect(file_get_contents($directory.'/src/Facades/Credits.php'))
        ->toContain('namespace RoundlyConsulting\Credits\Facades;')
        ->toContain('final class Credits extends Facade')
        ->toContain('public static function fake(): CreditsFake')
        ->toContain('return CreditsManager::class;')
        ->and(file_get_contents($directory.'/composer.json'))
        ->toContain('"Credits": "RoundlyConsulting\\\\Credits\\\\Facades\\\\Credits"');

    [$smokeExit, $smoke] = smokeRenamedApi($directory, 'Credits');

    expect($smokeExit)->toBe(0, "the renamed API did not run:\n".$smoke)
        ->and($smoke)->toBe("Hello, Ada!\nHello, Grace!\nRoundlyConsulting\\Credits\\Testing\\CreditsFake");
});

it('fails loudly when a file name keeps a template token', function (): void {
    $directory = scratchTemplate();

    // A directory: the rename moves files only, so this name survives — and the run must say so.
    mkdir($directory.'/stubs/package-template-extras', 0o777, true);

    [$exitCode, $output] = runRename($directory, 'Inspire');

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Rename incomplete')
        ->toContain('package-template-extras');
});

it('rewrites the namespace, composer name and env prefix', function (): void {
    $directory = scratchTemplate();

    [$exitCode] = runRename($directory, 'Inspire');
    expect($exitCode)->toBe(0);

    expect(file_get_contents($directory.'/src/InspireServiceProvider.php'))
        ->toContain('namespace RoundlyConsulting\Inspire;')
        ->toContain('class InspireServiceProvider');

    expect(file_get_contents($directory.'/composer.json'))
        ->toContain('roundly-consulting/inspire-for-laravel');

    expect(file_get_contents($directory.'/config/inspire.php'))
        ->toContain('INSPIRE_');
});

it('derives kebab-case from a multi-word StudlyCase domain', function (): void {
    $directory = scratchTemplate();

    // MediaLibrary is the case the naive `strtolower` would get wrong (medialibrary).
    [$exitCode] = runRename($directory, 'MediaLibrary');
    expect($exitCode)->toBe(0);

    expect($directory.'/config/media-library.php')->toBeFile()
        ->and($directory.'/src/MediaLibraryServiceProvider.php')->toBeFile();

    expect(file_get_contents($directory.'/composer.json'))
        ->toContain('roundly-consulting/media-library-for-laravel');

    expect(file_get_contents($directory.'/config/media-library.php'))
        ->toContain('MEDIA_LIBRARY_');
});

it('rejects a domain that is not StudlyCase', function (string $domain): void {
    $directory = scratchTemplate();

    [$exitCode, $output] = runRename($directory, $domain);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('StudlyCase');

    // A rejected run must not have touched anything — including deleting the script that
    // the user is about to re-run with a corrected argument.
    expect($directory.'/src/PackageTemplateServiceProvider.php')->toBeFile()
        ->and($directory.'/bin/rename-package.sh')->toBeFile();
})->with(['credits', 'media-library', '1Credits', 'Media Library']);

it('hands over the human title and the publishing checklist', function (): void {
    $directory = scratchTemplate();

    [$exitCode, $output] = runRename($directory, 'OpeningHours');
    expect($exitCode)->toBe(0);

    // New packages shipped with `# opening-hours-for-laravel` as their H1, no hero block and
    // an empty GitHub description: the checklist is where those steps get remembered.
    expect($output)
        ->toContain('# Opening Hours for Laravel')
        ->toContain('roundly-hero:start')
        ->toContain('gh repo edit --description');
});

/**
 * Assert a rejected run left the scratch copy exactly as it was — including the script
 * itself, which the user is about to re-run with a corrected argument.
 */
function expectUntouched(string $directory): void
{
    expect($directory.'/src/PackageTemplateServiceProvider.php')->toBeFile()
        ->and($directory.'/bin/rename-package.sh')->toBeFile()
        ->and((string) shell_exec(sprintf('git -C %s status --porcelain', escapeshellarg($directory))))->toBe('');
}

it('rejects a domain whose facade would shadow a Laravel core facade', function (string $domain): void {
    $directory = scratchTemplate();

    // `Auth` exited 0 and printed "Done", but composer.json then aliased `Auth` to the package
    // facade and the renamed package's own FacadeTest failed on its first run. PHP class names
    // are case-insensitive, so `Url` and `Db` shadow `URL` and `DB` just as well.
    [$exitCode, $output] = runRename($directory, $domain);

    expect($exitCode)->toBe(1, "the script accepted {$domain}:\n".$output)
        ->and($output)->toContain('Laravel core facade')
        ->toContain("bash bin/rename-package.sh {$domain} <ApiName>");

    expectUntouched($directory);
})->with(['Auth', 'Cache', 'Http', 'Log', 'Mail', 'Queue', 'Storage', 'Event', 'Config', 'Str', 'Number', 'Url', 'Db', 'Redis', 'Facade']);

it('rejects a domain whose config handle is one Laravel ships', function (string $domain): void {
    $directory = scratchTemplate();

    // `mergeConfigFrom(…, 'database')` merges the package's keys into the framework's own
    // config, and its publish tag targets the host's config/database.php.
    [$exitCode, $output] = runRename($directory, $domain);

    expect($exitCode)->toBe(1, "the script accepted {$domain}:\n".$output)
        ->and($output)->toContain('config handle')
        ->toContain("bash bin/rename-package.sh {$domain} <ApiName>");

    expectUntouched($directory);
})->with(['Database', 'Filesystems', 'Logging', 'Services', 'Cors', 'Hashing', 'Images', 'Broadcasting']);

it('rejects every facade, alias and config handle the installed Laravel ships', function (): void {
    $directory = scratchTemplate();

    // The script runs before `composer install`, so it cannot ask Laravel — it carries its own
    // list. This pins that list against the framework each CI leg installs (12 and 13): a new
    // Laravel facade or config file turns this red instead of slipping through.
    $framework = dirname((string) (new ReflectionClass(Application::class))->getFileName(), 4);

    $names = array_unique([
        ...array_map(static fn (string $file): string => basename($file, '.php'), glob($framework.'/src/Illuminate/Support/Facades/*.php') ?: []),
        ...array_keys(Facade::defaultAliases()->all()),
        ...array_map(static fn (string $file): string => Str::studly(basename($file, '.php')), glob($framework.'/config/*.php') ?: []),
    ]);

    expect(count($names))->toBeGreaterThan(40);

    $accepted = [];

    foreach ($names as $name) {
        [$exitCode, $output] = runRename($directory, $name);

        if ($exitCode !== 1 || ! str_contains($output, 'Laravel')) {
            $accepted[] = $name;
        }
    }

    expect($accepted)->toBe([], 'the script accepts Laravel core names: '.implode(', ', $accepted));

    expectUntouched($directory);
});

it('scaffolds a clashing domain under its own public API name', function (): void {
    $directory = scratchTemplate();

    // auth-for-laravel is exactly this: namespace RoundlyConsulting\Auth, everything that
    // would clash with Laravel's Auth (facade, alias, config handle, env prefix, provider)
    // named Authentication. It used to be done by hand after the script.
    [$exitCode, $output] = runRename($directory, 'Auth', 'Authentication');
    expect($exitCode)->toBe(0, "the rename script failed:\n".$output);

    expect($directory.'/src/AuthenticationServiceProvider.php')->toBeFile()
        ->and($directory.'/src/AuthenticationManager.php')->toBeFile()
        ->and($directory.'/src/Facades/Authentication.php')->toBeFile()
        ->and($directory.'/src/Testing/AuthenticationFake.php')->toBeFile()
        ->and($directory.'/src/Actions/ExampleAuthenticationAction.php')->toBeFile()
        ->and($directory.'/src/DataTransferObjects/ExampleAuthenticationData.php')->toBeFile()
        ->and($directory.'/config/authentication.php')->toBeFile();

    expect(file_get_contents($directory.'/composer.json'))
        ->toContain('"name": "roundly-consulting/auth-for-laravel"')
        ->toContain('"RoundlyConsulting\\\\Auth\\\\": "src"')
        ->toContain('"RoundlyConsulting\\\\Auth\\\\Tests\\\\": "tests"')
        ->toContain('"RoundlyConsulting\\\\Auth\\\\AuthenticationServiceProvider"')
        ->toContain('"Authentication": "RoundlyConsulting\\\\Auth\\\\Facades\\\\Authentication"')
        ->toContain('https://github.com/roundly-consulting/auth-for-laravel')
        ->not->toContain('"Auth":')
        ->and(file_get_contents($directory.'/src/AuthenticationServiceProvider.php'))
        ->toContain('namespace RoundlyConsulting\Auth;')
        ->toContain("->name('authentication')")
        ->toContain("'authentication.enabled'")
        ->and(file_get_contents($directory.'/config/authentication.php'))
        ->toContain("env('AUTHENTICATION_ENABLED'")
        ->and(file_get_contents($directory.'/tests/ArchTest.php'))
        ->toContain("ArchPresets::strictTypes('RoundlyConsulting\\Auth');")
        ->and(file_get_contents($directory.'/README.md'))
        ->toContain('# auth-for-laravel')
        ->toContain('private AuthenticationManager $authentication')
        ->toContain('use RoundlyConsulting\Auth\Facades\Authentication;');

    expect(leftoverTemplateTokens($directory))->toBe('')
        ->and(leftoverTemplateNames($directory))->toBe('');

    [$smokeExit, $smoke] = smokeRenamedApi($directory, 'Auth', 'Authentication');

    expect($smokeExit)->toBe(0, "the renamed API did not run:\n".$smoke)
        ->and($smoke)->toBe("Hello, Ada!\nHello, Grace!\nRoundlyConsulting\\Auth\\Testing\\AuthenticationFake");

    exec(sprintf(
        '%s %s --test --config=%s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(dirname(__DIR__, 2).'/vendor/bin/pint'),
        escapeshellarg($directory.'/pint.json'),
        escapeshellarg($directory),
    ), $pint, $pintExit);

    expect($pintExit)->toBe(0, "Pint rejects the renamed tree:\n".implode("\n", $pint));

    // The checklist names what the second argument drove, so nobody hunts for AuthManager.
    expect($output)->toContain('Authentication');
});

it('rejects a public API name that is itself a Laravel core name', function (string $name, string $reason): void {
    $directory = scratchTemplate();

    [$exitCode, $output] = runRename($directory, 'Auth', $name);

    expect($exitCode)->toBe(1, "the script accepted {$name}:\n".$output)
        ->and($output)->toContain($reason);

    expectUntouched($directory);
})->with([
    ['Cache', 'Laravel core facade'],
    ['Session', 'Laravel core facade'],
    ['Database', 'config handle'],
    ['authentication', 'StudlyCase'],
]);

it('rejects names whose generated classes or package name collide', function (string $domain, ?string $name, string $reason): void {
    $directory = scratchTemplate();

    [$exitCode, $output] = runRename($directory, $domain, $name);

    expect($exitCode)->toBe(1, "the script accepted {$domain} {$name}:\n".$output)
        ->and($output)->toContain($reason);

    expectUntouched($directory);
})->with([
    // `roundly-consulting/testing-for-laravel` would require-dev itself, and its namespace
    // would overlap the Tier-0 dependency it builds on. Same for the toolkit.
    'the testing dependency' => ['Testing', null, 'testing-for-laravel'],
    'the toolkit dependency' => ['PackageToolkit', null, 'package-toolkit-for-laravel'],
    // Renaming to the template's own name is a no-op the leftover check can only fail after
    // the script has already deleted itself.
    'the template itself' => ['PackageTemplate', null, 'package-template-for-laravel'],
    'the template as API name' => ['Credits', 'PackageTemplate', 'package-template-for-laravel'],
    'a suffixed domain' => ['CreditsForLaravel', null, 'without the -for-laravel suffix'],
    // `class PackageServiceProvider extends PackageServiceProvider`: the provider's own name
    // collides with the toolkit base class it imports — a fatal error on the first boot.
    'the toolkit base provider' => ['Package', null, 'PackageServiceProvider'],
    // The facade class is named exactly after the API name: `final class Match extends Facade`
    // is a parse error, while `RoundlyConsulting\Match` is a legal namespace.
    'a reserved word' => ['Match', null, 'PHP reserved word'],
    'a reserved word as API name' => ['Lists', 'List', 'PHP reserved word'],
]);

it('accepts a reserved word as the domain when the API name is not one', function (): void {
    $directory = scratchTemplate();

    [$exitCode, $output] = runRename($directory, 'Match', 'Matches');
    expect($exitCode)->toBe(0, "the rename script failed:\n".$output);

    [$smokeExit, $smoke] = smokeRenamedApi($directory, 'Match', 'Matches');

    expect($smokeExit)->toBe(0, "the renamed API did not run:\n".$smoke);
});

it('rejects more than two arguments', function (): void {
    $directory = scratchTemplate();

    exec(sprintf('bash %s Credits Coins Extra 2>&1', escapeshellarg($directory.'/bin/rename-package.sh')), $output, $exitCode);

    expect($exitCode)->toBe(1)
        ->and(implode("\n", $output))->toContain('Usage: bash bin/rename-package.sh <StudlyCaseDomain> [<StudlyCaseApiName>]');

    expectUntouched($directory);
});

it('rewrites the camelCase form of the name too', function (): void {
    $directory = scratchTemplate();

    // The README's DI example injects `PackageTemplateManager $packageTemplate`. The camelCase
    // variable matched none of the three case-sensitive tokens, survived the rename as
    // `MediaLibraryManager $packageTemplate`, and both leftover checks called the tree clean.
    [$exitCode, $output] = runRename($directory, 'MediaLibrary');
    expect($exitCode)->toBe(0, "the rename script failed:\n".$output);

    expect(file_get_contents($directory.'/README.md'))
        ->toContain('private MediaLibraryManager $mediaLibrary')
        ->toContain('$this->mediaLibrary->example(');

    expect(leftoverTemplateTokens($directory))->toBe('');
});

it('fails loudly on a template token in a casing the rewrite does not know', function (string $file, string $token): void {
    $directory = scratchTemplate();

    // Inside the rewrite's own file filter, in a casing none of its substitutions target: only
    // a case-insensitive leftover check can see it.
    file_put_contents($directory.'/'.$file, $token."\n");

    [$exitCode, $output] = runRename($directory, 'Inspire');

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Rename incomplete')
        ->toContain($file);
})->with([
    ['NOTES.md', 'packagetemplate'],
    ['notes.json', '"Package-Template"'],
]);

it('fails loudly when a file name keeps a template token in another casing', function (): void {
    $directory = scratchTemplate();

    mkdir($directory.'/stubs/Package_Template_Extras', 0o777, true);

    [$exitCode, $output] = runRename($directory, 'Inspire');

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Rename incomplete')
        ->toContain('Package_Template_Extras');
});
