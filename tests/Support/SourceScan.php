<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Token-level scans of the package source for the bespoke arch rules. Comments are never
 * scanned, so a docblock that mentions `now()` is not a violation.
 */
final class SourceScan
{
    /**
     * @return list<string>
     */
    public static function files(string $directory): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<array{0: int|string, 1: string}>
     */
    public static function tokens(string $file): array
    {
        $tokens = [];

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            $token = is_array($token) ? [$token[0], $token[1]] : [$token, $token];

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Calls of the global `now()` helper or `Date|Carbon|CarbonImmutable::now()`.
     */
    public static function readsTheClock(string $file): bool
    {
        $tokens = self::tokens($file);

        foreach ($tokens as $i => [$type, $text]) {
            if ($type !== T_STRING || strtolower($text) !== 'now' || ($tokens[$i + 1][1] ?? null) !== '(') {
                continue;
            }

            $previous = $tokens[$i - 1][0] ?? null;

            if ($previous === T_OBJECT_OPERATOR || $previous === T_NULLSAFE_OBJECT_OPERATOR || $previous === T_FUNCTION) {
                continue;
            }

            if ($previous === T_DOUBLE_COLON && ! in_array($tokens[$i - 2][1] ?? '', ['Date', 'Carbon', 'CarbonImmutable'], true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * A `static` property or static local variable: process-wide state that outlives a
     * request under Octane.
     */
    public static function declaresStaticState(string $file): bool
    {
        $tokens = self::tokens($file);
        $skippable = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_READONLY, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_ARRAY, '?', '|'];

        foreach ($tokens as $i => [$type]) {
            if ($type !== T_STATIC) {
                continue;
            }

            for ($j = $i + 1; isset($tokens[$j]); $j++) {
                if ($tokens[$j][0] === T_VARIABLE) {
                    return true;
                }

                if (! in_array($tokens[$j][0], $skippable, true)) {
                    break;
                }
            }
        }

        return false;
    }

    /**
     * Every fully qualified class name the file imports or spells out.
     *
     * @return list<string>
     */
    public static function references(string $file): array
    {
        $names = [];

        foreach (self::tokens($file) as [$type, $text]) {
            if ($type === T_NAME_QUALIFIED || $type === T_NAME_FULLY_QUALIFIED) {
                $names[] = ltrim($text, '\\');
            }
        }

        return $names;
    }
}
