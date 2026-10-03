<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

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

it('reads schedules.queue.enabled as a boolean', function (string|bool $value, bool $expected): void {
    config()->set('lifecycle.schedules.queue.enabled', $value);

    Artisan::call('about', ['--only' => 'lifecycle']);

    expect(Artisan::output())->toMatch('/Queued sweeps\W+'.($expected ? 'ON' : 'OFF').'\b/');
})->with('booleans');

it('reads transactions.mysql_read_committed as a boolean', function (string|bool $value, bool $expected): void {
    config()->set('lifecycle.transactions.mysql_read_committed', $value);

    Artisan::call('about', ['--only' => 'lifecycle']);

    expect(Artisan::output())->toMatch('/MySQL quota isolation\W+'.($expected ? 'READ COMMITTED' : 'locking reads').'\b/');
})->with('booleans');

it('refuses a boolean it cannot parse instead of falling back to the default', function (string $key): void {
    config()->set($key, 'disabled');

    expect(fn () => Artisan::call('about', ['--only' => 'lifecycle']))->toThrow(InvalidConfigurationException::class, 'disabled');
})->with(['lifecycle.strict_writes', 'lifecycle.actor.from_auth', 'lifecycle.schedules.queue.enabled', 'lifecycle.transactions.mysql_read_committed']);
