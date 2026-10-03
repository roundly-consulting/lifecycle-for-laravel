<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use RoundlyConsulting\Lifecycle\Tests\TestCase;

/**
 * The suite's base case booted from a throwaway mirror of the testbench skeleton, one per test.
 *
 * `make:lifecycle` writes into app/, and `vendor:publish --tag=lifecycle-stubs` into
 * base_path('stubs') — a destination the provider fixes when it REGISTERS, before any test
 * hook runs, and one no use*Path() setter reaches. So the app is created on a base path of
 * its own: every skeleton entry links back to the real one (same config, migrations,
 * bootstrap cache — nothing else changes), except the paths the tests write, which are real
 * sandbox paths. Generated classes and published stubs land in the mirror, never in the
 * shared skeleton other parallel processes boot from.
 */
abstract class GeneratorSandboxTestCase extends TestCase
{
    /**
     * What the tests write: never linked back to the skeleton, or the write would land
     * there through the link. Their parent directories are real sandbox directories whose
     * other entries are linked.
     */
    private const array WRITTEN = ['stubs', 'app/Lifecycles'];

    private string $sandbox = '';

    protected function getApplicationBasePath(): string
    {
        if ($this->sandbox === '') {
            $this->sandbox = sys_get_temp_dir().'/lifecycle-generate-'.bin2hex(random_bytes(6));

            mkdir($this->sandbox, 0777, true);
            $this->mirror(static::applicationBasePath(), '');
        }

        return $this->sandbox;
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->sandbox !== '') {
                // Links are unlinked, never followed: the skeleton itself is untouched.
                (new Filesystem)->deleteDirectory($this->sandbox);
                $this->sandbox = '';
            }
        }
    }

    private function mirror(string $skeleton, string $directory): void
    {
        foreach (scandir($skeleton.'/'.$directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $relative = ltrim($directory.'/'.$entry, '/');

            if (in_array($relative, self::WRITTEN, true)) {
                continue;
            }

            if ($this->holdsWrittenPath($relative)) {
                mkdir($this->sandbox.'/'.$relative);
                $this->mirror($skeleton, $relative);

                continue;
            }

            symlink($skeleton.'/'.$relative, $this->sandbox.'/'.$relative);
        }
    }

    private function holdsWrittenPath(string $relative): bool
    {
        foreach (self::WRITTEN as $written) {
            if (str_starts_with($written, $relative.'/')) {
                return true;
            }
        }

        return false;
    }
}
