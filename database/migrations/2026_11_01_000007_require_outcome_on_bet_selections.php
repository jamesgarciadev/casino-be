<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settlement and cash-out go through a pick's outcome, so a pick without one would stay pending forever. The backfill
 * linked every existing pick; from here on, a write that leaves it out fails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bet_selections', function (Blueprint $table) {
            $table->unsignedBigInteger('market_outcome_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('bet_selections', function (Blueprint $table) {
            $table->unsignedBigInteger('market_outcome_id')->nullable()->change();
        });
    }
};
