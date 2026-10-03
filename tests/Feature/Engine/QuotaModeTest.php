<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Engine\QuotaGate;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\QuotaLock;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;

it('decides per driver, transaction depth and isolation whether the count takes locks', function (?DatabaseDriver $driver, int $level, bool $readCommitted, bool $locks): void {
    expect(QuotaGate::countTakesLocks($driver, $level, $readCommitted))->toBe($locks);
})->with([
    'pgsql, level 1, switched' => [DatabaseDriver::Pgsql, 1, true, false],
    'pgsql, level 1, not switched' => [DatabaseDriver::Pgsql, 1, false, false],
    'pgsql, level 2, switched' => [DatabaseDriver::Pgsql, 2, true, false],
    'pgsql, level 2, not switched' => [DatabaseDriver::Pgsql, 2, false, false],
    'sqlite, level 1, switched' => [DatabaseDriver::Sqlite, 1, true, false],
    'sqlite, level 1, not switched' => [DatabaseDriver::Sqlite, 1, false, false],
    'sqlite, level 2, switched' => [DatabaseDriver::Sqlite, 2, true, false],
    'sqlite, level 2, not switched' => [DatabaseDriver::Sqlite, 2, false, false],
    'mysql, its own switched transaction' => [DatabaseDriver::Mysql, 1, true, false],
    'mysql, its own transaction, opt-out' => [DatabaseDriver::Mysql, 1, false, true],
    'mysql, nested in its switched transaction' => [DatabaseDriver::Mysql, 2, true, true],
    'mysql, inside the host transaction' => [DatabaseDriver::Mysql, 2, false, true],
    'mariadb, its own switched transaction' => [DatabaseDriver::Mariadb, 1, true, false],
    'mariadb, its own transaction, opt-out' => [DatabaseDriver::Mariadb, 1, false, true],
    'mariadb, nested in its switched transaction' => [DatabaseDriver::Mariadb, 2, true, true],
    'mariadb, inside the host transaction' => [DatabaseDriver::Mariadb, 2, false, true],
    'unknown, level 1, switched' => [null, 1, true, true],
    'unknown, level 1, not switched' => [null, 1, false, true],
    'unknown, level 2, switched' => [null, 2, true, true],
    'unknown, level 2, not switched' => [null, 2, false, true],
]);

it('takes no mutex for a self-transition or a doomed apply', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->transition('touch')->from('b')->to('b')->allowSelf();
        $l->transition('locked')->from('a')->to('b')->requiresActor();
        $l->state('b')->quota(1, 'user_id');
    });
    $document = Document::factory()->create(['user_id' => 3]);

    rescue(fn () => $document->transition('locked'), report: false);

    expect(QuotaLock::query()->count())->toBe(0);

    $document->transition('go');
    QuotaLock::query()->delete();
    $document->transition('touch');

    expect(QuotaLock::query()->count())->toBe(0);
});

it('caps the reported count at the maximum', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->quota(1));
    Document::factory()->count(3)->create()->each(fn (Document $d) => Document::query()->whereKey($d->id)->update(['status' => 'b']));

    expect(Lifecycles::for(Document::factory()->create())->check('go')->first()?->params['current'])->toBe(1);
});
