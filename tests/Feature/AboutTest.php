<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/**
 * The `php artisan about` section, pinned with testing-for-laravel's render check: it proves
 * the capture is not empty before anything else is trusted. Add the real secrets to the
 * first argument once the package reads any (API keys, signing keys, DSNs).
 */
it('renders its about section', function (): void {
    expect('lifecycle')->toLeakNoSecrets([], mustRender: ['Enabled', 'YES']);
});

it('reads the enabled flag the way an env string means it', function (string|bool $value, string $shown): void {
    // env() only maps true/false/(true)/(false); `LIFECYCLE_ENABLED=off` arrives as the
    // string 'off', which a bare truthiness check reported as YES.
    config()->set('lifecycle.enabled', $value);

    Artisan::call('about', ['--only' => 'lifecycle']);

    expect(Artisan::output())->toMatch('/Enabled\W+'.$shown.'\b/');
})->with([
    ['off', 'NO'],
    ['no', 'NO'],
    ['0', 'NO'],
    [false, 'NO'],
    ['on', 'YES'],
    ['yes', 'YES'],
    ['1', 'YES'],
    [true, 'YES'],
]);
