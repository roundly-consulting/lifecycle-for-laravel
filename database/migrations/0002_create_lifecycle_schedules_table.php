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

        // Future system transitions: TTL expiries and scheduled transitions. An open row holds
        // its slot (`@expiry` or the transition name); a finished one releases it (NULL), so the
        // nullable unique index works as a portable partial unique index.
        Schema::create('lifecycle_schedules', function (Blueprint $table) use ($subject, $actor): void {
            $table->id();
            $table->morphKey('subject', $subject);
            $table->string('lifecycle', 64);
            $table->string('kind', 16);
            $table->string('transition', 64);
            $table->string('for_state', 64);
            $table->string('status', 16)->default('pending');
            $table->string('pending_slot', 72)->nullable();
            $table->dateTime('due_at');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('next_warn_at')->nullable();
            $table->unsignedTinyInteger('warnings_sent')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_denial', 64)->nullable();
            $table->string('outcome', 32)->nullable();
            // An expiry set by expireAt()/extend()/renew(); on a cancelled expiry row, the mark
            // neverExpire() leaves for the rest of the stay (kept by lifecycle:prune while it lasts).
            $table->boolean('is_override')->default(false);
            $table->morphKey('scheduled_by', $actor, true);
            $table->jsonb('context')->nullable();
            // Plain indexed columns, not foreign keys: an FK here would form a cycle with
            // lifecycle_transitions.schedule_id.
            $table->unsignedBigInteger('created_by_transition_id')->nullable()->index('lifecycle_schedules_created_by_idx');
            $table->unsignedBigInteger('cancelled_by_transition_id')->nullable()->index('lifecycle_schedules_cancelled_by_idx');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id', 'lifecycle', 'pending_slot'], 'lifecycle_schedules_slot_unique');
            $table->index(['status', 'due_at'], 'lifecycle_schedules_due_idx');
            $table->index(['status', 'next_warn_at'], 'lifecycle_schedules_warn_idx');
            $table->index(['subject_type', 'subject_id', 'lifecycle', 'status'], 'lifecycle_schedules_subject_idx');
            $table->index('finished_at', 'lifecycle_schedules_finished_idx');
        });
    }
};
