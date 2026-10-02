<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/**
 * Env strings for every boolean key: `off`/`no`/`0`/`false`/`''` are false, `on`/`yes`/`1`/`true`
 * true — a bare truthiness check would read `'off'` as on.
 */
dataset('booleans', [
    ['off', false], ['no', false], ['0', false], ['false', false], ['', false],
    ['on', true], ['yes', true], ['1', true], ['true', true], [true, true], [false, false],
]);

it('reads strict_writes and actor.from_auth as booleans in the about section', function (string|bool $value, bool $expected): void {
    config()->set('lifecycle.strict_writes', $value);
    config()->set('lifecycle.actor.from_auth', $value);

    Artisan::call('about', ['--only' => 'lifecycle']);
    $output = Artisan::output();
    $shown = $expected ? 'ON' : 'OFF';

    expect($output)->toMatch('/Strict state writes\W+'.$shown.'\b/')
        ->and($output)->toMatch('/Actor from auth\W+'.$shown.'\b/');
})->with('booleans');
