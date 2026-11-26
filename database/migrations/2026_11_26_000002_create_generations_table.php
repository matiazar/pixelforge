<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // ULID: also the Idempotency-Key and metadata.local_id sent to the provider.
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('form_token', 64);
            $table->ulid('retry_of_id')->nullable()->unique();
            $table->string('provider_id')->nullable()->unique();
            $table->string('api_key_fingerprint', 16)->nullable();

            // Sent identically on every attempt.
            $table->jsonb('request_payload');

            // pending (our queue) < queued < processing < succeeded | failed
            $table->string('status', 16)->default('pending');
            $table->string('failure_source', 16)->nullable(); // provider | local
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->jsonb('rates')->nullable(); // fixed by the provider at creation
            $table->jsonb('usage')->nullable();

            // User-facing price range shown before submitting, nano-USD. The hold is estimate_max_nano.
            $table->bigInteger('estimate_min_nano');
            $table->bigInteger('estimate_max_nano');
            $table->bigInteger('cost_nano')->nullable();  // provider cost
            $table->bigInteger('price_nano')->nullable(); // charged to the user

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->unsignedInteger('attempts')->default(0);
            $table->boolean('ambiguous')->default(false);
            $table->timestamp('attempt_started_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Double-submit guard, per user.
            $table->unique(['user_id', 'form_token']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('completed_at');
        });

        // Self reference added after the primary key exists.
        Schema::table('generations', function (Blueprint $table) {
            $table->foreign('retry_of_id')->references('id')->on('generations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
