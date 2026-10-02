<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\InlineLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Factories\DocumentFactory;

/**
 * The engine's test subject: its lifecycle is whatever the test defines inline
 * (`defineDocumentLifecycle()`).
 */
final class Document extends Model implements LifecycleSubject
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use HasLifecycle;
    use SoftDeletes;

    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return ['status' => InlineLifecycle::class];
    }

    protected function casts(): array
    {
        return [
            'publish_at' => 'datetime',
            'editable_until' => 'datetime',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'meta' => 'array',
            'flag' => 'boolean',
        ];
    }

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }
}
