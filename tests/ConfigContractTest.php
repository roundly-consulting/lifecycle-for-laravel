<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

/**
 * The config contract, pinned in both directions from day one:
 *
 *  - forward — every key the code reads is shipped. A key the code reads but the file never
 *    ships is a null in every application that did not publish the config.
 *  - reverse — every shipped leaf is read. A key nothing reads is dead config that lies to
 *    the host: it invites somebody to set it, and then nothing happens.
 *
 * Keep both directions green as the package's real keys replace the placeholder one. Reads
 * are token-scraped from `src/` — plus a sibling `database/` and `routes/` when they exist —
 * so a key read from outside that scope (a view, a directory this call does not name) scrapes
 * as unread and is reported exactly like a dead one: widen the scanned directories rather
 * than allow-list it. The call below passes no options; `toSatisfyConfigContract` in
 * testing-for-laravel documents the ones a growing package needs, such as excluding a file
 * that renders keys instead of reading them.
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../config/lifecycle.php')->toSatisfyConfigContract(__DIR__.'/../src');
});

it('never claims a config handle Laravel ships itself', function (): void {
    // mergeConfigFrom() would merge this package's keys into the framework's own config, and
    // the publish tag would target the host's file of the same name (`auth`, `database`, …).
    $framework = dirname((string) (new ReflectionClass(Application::class))->getFileName(), 4);

    expect($framework.'/config/app.php')->toBeFile()
        ->and($framework.'/config/'.basename(__DIR__.'/../config/lifecycle.php'))->not->toBeFile();
});
