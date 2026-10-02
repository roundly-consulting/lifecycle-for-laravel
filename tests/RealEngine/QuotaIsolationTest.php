<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Support\Racer;

/**
 * The racer's transaction reads before it waits for the quota mutex — on MySQL REPEATABLE
 * READ that fixes its snapshot before the winner commits. The count after the mutex must
 * still see the winner (a locking read on MySQL, a fresh statement on pgsql READ COMMITTED).
 */
it('counts the winner that committed while the racer waited for the mutex', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->quota(1, 'user_id'));
    [$winner, $loser] = Document::factory()->count(2)->create(['user_id' => 9])->all();
    $racer = new Racer;

    DB::transaction(function () use ($winner, $loser, $racer): void {
        $winner->transition('go');

        $racer->run(function () use ($loser): string {
            return DB::transaction(function () use ($loser): string {
                Document::on('racer')->where('user_id', 9)->count();

                try {
                    Document::on('racer')->findOrFail($loser->id)->transition('go');

                    return 'entered';
                } catch (TransitionDeniedException $exception) {
                    return 'denied:'.implode(',', $exception->decision()->codes());
                }
            });
        })->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('denied:quota_exceeded')
        ->and(Document::query()->where('status', 'b')->count())->toBe(1);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
