<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use RoundlyConsulting\Lifecycle\Enums\DenialCode;
use RoundlyConsulting\Lifecycle\Enums\IssueCode;

dataset('translation files', ['denials', 'issues', 'labels', 'validation']);

it('ships the same keys in every language', function (string $file): void {
    $en = Arr::dot(require __DIR__.'/../../resources/lang/en/'.$file.'.php');
    $sk = Arr::dot(require __DIR__.'/../../resources/lang/sk/'.$file.'.php');

    expect($en)->not->toBeEmpty()
        ->and(array_keys($sk))->toBe(array_keys($en));
})->with('translation files');

it('translates every denial and issue code', function (): void {
    $denials = require __DIR__.'/../../resources/lang/en/denials.php';
    $issues = require __DIR__.'/../../resources/lang/en/issues.php';

    expect(array_keys($denials))->toBe(DenialCode::values()->all())
        ->and(array_keys($issues))->toBe(IssueCode::values()->all())
        ->and(trans('lifecycle::denials.frozen'))->toBe('This record is frozen.');
});
