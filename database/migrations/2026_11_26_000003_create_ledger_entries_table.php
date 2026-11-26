<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only. Signed amounts in nano-USD: topup +, hold -, release +, charge -.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('generation_id')->nullable()->constrained('generations')->restrictOnDelete();
            $table->string('type', 16);
            $table->bigInteger('amount_nano');
            $table->timestamp('created_at')->useCurrent();

            // One hold, one release, one charge per generation: no double billing.
            // NULL generation_id (top-ups) is not constrained by Postgres unique indexes.
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
