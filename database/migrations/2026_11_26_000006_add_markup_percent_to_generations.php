<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Markup in force when each generation was created (admin setting, DEC-011).
 * Existing rows were priced with the old fixed 25%. The default only backfills
 * them and is then dropped, so a create path that forgets the field fails
 * instead of silently using 25.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->unsignedSmallInteger('markup_percent')->default(25);
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->unsignedSmallInteger('markup_percent')->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('markup_percent');
        });
    }
};
