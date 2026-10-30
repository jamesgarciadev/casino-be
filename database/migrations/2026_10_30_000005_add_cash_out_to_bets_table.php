<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_out')->nullable()->after('payout')->comment('Credits paid when the bet was cashed out');
            // myBets filters by status and pages by id.
            $table->index(['user_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status', 'id']);
            $table->dropColumn('cash_out');
        });
    }
};
