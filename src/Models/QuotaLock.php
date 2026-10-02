<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Database\Factories\QuotaLockFactory;

/**
 * The mutex row of one quota partition: entering a quota'd state locks it first, so
 * concurrent entries into the same partition are serialised. Never pruned.
 *
 * @property int $id
 * @property string $subject_type
 * @property string $lifecycle
 * @property string $quota
 * @property string $scope_key
 * @property CarbonInterface|null $created_at
 */
final class QuotaLock extends Model
{
    /** @use HasFactory<QuotaLockFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'lifecycle_quota_locks';

    protected $guarded = [];

    protected static function newFactory(): QuotaLockFactory
    {
        return QuotaLockFactory::new();
    }
}
