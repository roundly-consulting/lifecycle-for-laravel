<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Engine\QuotaGate;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\QuotaLock;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;

it('decides per driver and transaction depth whether the count takes locks', function (?DatabaseDriver $driver, int $level, bool $locks): void {
    expect(QuotaGate::countTakesLocks($driver, $level))->toBe($locks);
})->with([
    'pgsql' => [DatabaseDriver::Pgsql, 2, false],
    'sqlite' => [DatabaseDriver::Sqlite, 1, false],
    'mysql, own transaction' => [DatabaseDriver::Mysql, 1, false],
    'mysql, inside the host transaction' => [DatabaseDriver::Mysql, 2, true],
    'mariadb, inside the host transaction' => [DatabaseDriver::Mariadb, 3, true],
    'mariadb, own transaction' => [DatabaseDriver::Mariadb, 1, false],
    'unknown' => [null, 1, true],
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
