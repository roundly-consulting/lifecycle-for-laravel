<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

return new class extends Migration
{
    public function up(): void
    {
        $subject = KeyType::fromConfig('lifecycle.key_type');
        $actor = KeyType::fromConfig('lifecycle.actor_key_type');

        // The append-only history: one row per state change, rollback step, adoption and
        // initialization.
        Schema::create('lifecycle_transitions', function (Blueprint $table) use ($subject, $actor): void {
            $table->id();
            $table->morphKey('subject', $subject);
            $table->string('lifecycle', 64);
            $table->string('kind', 16);
            $table->string('transition', 64)->nullable();
            $table->string('from_state', 64)->nullable();
            $table->string('to_state', 64);
            $table->morphKey('actor', $actor, true);
            $table->boolean('is_system')->default(false);
            $table->text('reason')->nullable();
            $table->jsonb('context')->nullable();
            $table->jsonb('snapshot')->nullable();
            $table->dateTime('previous_entered_at')->nullable();
            $table->jsonb('counter_before')->nullable();
            $table->unsignedInteger('version');
            $table->foreignId('reverts_id')->nullable()->constrained('lifecycle_transitions')->nullOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('lifecycle_schedules')->nullOnDelete();
            $table->string('idempotency_key', 191)->nullable();
            $table->dateTime('occurred_at');
            // Nullable unique columns accept many NULLs on every supported engine.
            $table->unique('reverts_id', 'lifecycle_transitions_reverts_unique');
            $table->unique('schedule_id', 'lifecycle_transitions_schedule_unique');
            $table->unique(['subject_type', 'subject_id', 'lifecycle', 'idempotency_key'], 'lifecycle_transitions_idem_unique');
            $table->index(['subject_type', 'subject_id', 'lifecycle', 'id'], 'lifecycle_transitions_stack_idx');
            $table->index('occurred_at', 'lifecycle_transitions_occurred_idx');
        });
    }
};
