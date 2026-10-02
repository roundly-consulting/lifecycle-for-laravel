<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Host-owned tables for the fixture subjects (not shipped).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('user');
            $table->boolean('admin')->default(false);
            $table->timestamps();
        });

        Schema::create('listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('title')->default('listing');
            $table->string('status', 64)->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'user_id']);
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('title')->default('document');
            $table->string('status', 64)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->integer('quantity')->nullable();
            $table->boolean('flag')->nullable();
            $table->json('meta')->nullable();
            $table->dateTime('publish_at')->nullable();
            $table->dateTime('editable_until')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('status')->nullable();
            $table->string('payment_status', 64)->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('plain_models', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 64)->nullable();
        });
    }
};
