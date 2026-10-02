<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Lifecycle\Tests\TestCase;

/**
 * Subjects keyed by uuid (actors stay bigint): the package migrations run with
 * `key_type = uuid`, so the morph columns are uuid columns on a strict engine.
 */
abstract class UuidKeysTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [...parent::configBeforeBoot(), 'lifecycle.key_type' => 'uuid'];
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('uuid_listings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 64)->nullable();
            $table->timestamps();
        });
    }
}
