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

        // One row per (subject, lifecycle): a mirror of the host attribute plus what the engine
        // needs under the lock — entry time, version, occurrence counters and the freeze.
        Schema::create('lifecycle_states', function (Blueprint $table) use ($subject, $actor): void {
            $table->id();
            $table->morphKey('subject', $subject);
            $table->string('lifecycle', 64);
            $table->string('state', 64);
            $table->string('previous_state', 64)->nullable();
            $table->dateTime('entered_at');
            $table->unsignedInteger('version')->default(0);
            $table->jsonb('counters')->nullable();
            $table->dateTime('frozen_at')->nullable();
            $table->dateTime('frozen_until')->nullable();
            $table->text('frozen_reason')->nullable();
            // Positional nullable: Blueprint macros go through __call, which has no named arguments.
            $table->morphKey('frozen_by', $actor, true);
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id', 'lifecycle'], 'lifecycle_states_subject_unique');
            $table->index(['lifecycle', 'state', 'entered_at'], 'lifecycle_states_dwell_idx');
            $table->index(['lifecycle', 'frozen_at'], 'lifecycle_states_frozen_idx');
        });
    }
};
