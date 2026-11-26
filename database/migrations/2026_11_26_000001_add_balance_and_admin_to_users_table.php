<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Only the seeder sets is_admin; it is never mass-assignable.
            $table->boolean('is_admin')->default(false);
            // Cache of the ledger sum, in nano-USD. Written only by LedgerService.
            $table->bigInteger('balance_nano')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'balance_nano']);
        });
    }
};
