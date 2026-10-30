<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bets become a stake on one or more picks: a single has one selection, a combo has one per match.
 * Existing bets keep their pick as their only selection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bet_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixture_id')->constrained()->cascadeOnDelete();
            $table->string('side', 1);
            $table->decimal('odds', 5, 2)->comment('Snapshot of the fixture odds when the bet was placed');
            $table->string('status', 16)->default('pending');
            $table->timestamps();

            // A combo can't back the same match twice.
            $table->unique(['bet_id', 'fixture_id']);
            $table->index(['fixture_id', 'status']);
        });

        DB::table('bet_selections')->insertUsing(
            ['bet_id', 'fixture_id', 'side', 'odds', 'status', 'created_at', 'updated_at'],
            DB::table('bets')->select('id', 'fixture_id', 'side', 'odds', 'status', 'created_at', 'updated_at'),
        );

        Schema::table('bets', function (Blueprint $table) {
            $table->string('type', 16)->default('single')->after('user_id');
            $table->decimal('odds', 12, 2)->comment('Total odds: the product of the selections\' odds')->change();
            // MySQL keeps this index for the foreign key, so the key goes first.
            $table->dropForeign(['fixture_id']);
            $table->dropIndex(['fixture_id', 'status']);
            $table->dropColumn(['fixture_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->foreignId('fixture_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->string('side', 1)->nullable()->after('fixture_id');
        });

        // Combos have no single-match form; only their first pick survives a rollback.
        foreach (DB::table('bet_selections')->orderBy('id')->get()->unique('bet_id') as $selection) {
            DB::table('bets')->where('id', $selection->bet_id)->update(['fixture_id' => $selection->fixture_id, 'side' => $selection->side]);
        }

        Schema::table('bets', function (Blueprint $table) {
            $table->dropColumn('type');
            $table->decimal('odds', 5, 2)->comment('Snapshot of the fixture odds when the bet was placed')->change();
            $table->index(['fixture_id', 'status']);
        });

        Schema::dropIfExists('bet_selections');
    }
};
