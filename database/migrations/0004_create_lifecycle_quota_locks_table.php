<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One mutex row per (model, lifecycle, quota, scope values): entering a quota'd state
        // locks it, so concurrent entries into one partition are serialised.
        Schema::create('lifecycle_quota_locks', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 191);
            $table->string('lifecycle', 64);
            $table->string('quota', 64);
            $table->string('scope_key', 191);
            $table->timestamp('created_at')->nullable();
            $table->unique(['subject_type', 'lifecycle', 'quota', 'scope_key'], 'lifecycle_quota_locks_unique');
        });
    }
};
