<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\InlineLifecycle;

/**
 * The package's own timestamps follow the package models' format — never the subject's
 * `$dateFormat` (a unix-timestamp host table would write an integer into a datetime column,
 * which PostgreSQL and strict MySQL refuse).
 */
final class UnixTimestampDocument extends Model implements LifecycleSubject
{
    use HasLifecycle;
    use SoftDeletes;

    protected $table = 'unix_timestamp_documents';

    protected $guarded = [];

    protected $dateFormat = 'U';

    public function lifecycleDefinitions(): array
    {
        return ['status' => InlineLifecycle::class];
    }
}

it('writes schedule timestamps in the package format for a subject with its own date format', function (): void {
    Schema::create('unix_timestamp_documents', function (Blueprint $table): void {
        $table->id();
        $table->string('status', 64)->nullable();
        $table->unsignedBigInteger('created_at')->nullable();
        $table->unsignedBigInteger('updated_at')->nullable();
        $table->unsignedBigInteger('deleted_at')->nullable();
    });
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l, finish: fn (TransitionBuilder $f) => $f->allowSystem());
        $l->state('b')->ttl('10 days')->expiresVia('finish');
    });
    $document = UnixTimestampDocument::query()->create();
    $document->transition('go');
    $document->delete();   // pause()
    $document->restore();  // resume()
    $document->transition('finish'); // leave()

    $stamps = LifecycleSchedule::query()->toBase()->pluck('updated_at')->all();

    expect($stamps)->not->toBeEmpty()
        ->and(array_filter($stamps, static fn (mixed $stamp): bool => preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', (string) $stamp) !== 1))->toBe([]);
});
