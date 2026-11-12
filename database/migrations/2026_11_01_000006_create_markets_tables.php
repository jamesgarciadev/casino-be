<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A match offers many markets (winner, map handicap, total rounds, pistol round…), each with its own outcomes and
 * odds. A pick now points at an outcome. Every existing match gets its winner market, and every existing pick points
 * at that market's outcome for its side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('markets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixture_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64)->comment('Stable id from the feed, e.g. map3.total_rounds.22.5');
            $table->string('type', 32);
            $table->unsignedTinyInteger('map')->nullable();
            $table->unsignedTinyInteger('half')->nullable();
            $table->unsignedSmallInteger('round')->nullable();
            $table->decimal('line', 6, 1)->nullable();
            $table->string('side', 1)->nullable()->comment('The team a team-total market is about');
            $table->string('status', 16)->default('open');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['fixture_id', 'key']);
            $table->index(['fixture_id', 'status']);
        });

        Schema::create('market_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->string('key', 16)->comment('a, b, draw, over, under, odd, even, yes, no, or a map score like 2:1');
            $table->decimal('odds', 5, 2);
            $table->string('result', 8)->nullable();
            $table->timestamps();

            $table->unique(['market_id', 'key']);
        });

        Schema::table('fixtures', function (Blueprint $table) {
            $table->unsignedTinyInteger('best_of')->default(1)->after('team_b_acronym');
            $table->json('maps')->nullable()->after('score_b')->comment('Per map: name, rounds per team, winner');
        });

        Schema::table('bet_selections', function (Blueprint $table) {
            $table->foreignId('market_outcome_id')->nullable()->after('fixture_id')->constrained()->cascadeOnDelete();
            $table->string('side', 1)->nullable()->comment('Only for picks on the winner market')->change();
        });

        // Set-based, so it stays fast however many matches the simulator has played.
        $now = now()->toDateTimeString();
        $settled = fn (string $table = '') => "{$table}state = 'finished' and {$table}winner is not null";

        DB::table('markets')->insertUsing(
            ['fixture_id', 'key', 'type', 'status', 'sort', 'created_at', 'updated_at'],
            DB::table('fixtures')->selectRaw("id, 'winner', 'winner', case when {$settled()} then 'settled' else 'open' end, 0, ?, ?", [$now, $now]),
        );

        foreach (['a' => 'odds_a', 'b' => 'odds_b'] as $side => $odds) {
            DB::table('market_outcomes')->insertUsing(
                ['market_id', 'key', 'odds', 'result', 'created_at', 'updated_at'],
                DB::table('markets')
                    ->join('fixtures', 'fixtures.id', '=', 'markets.fixture_id')
                    ->where('markets.key', 'winner')
                    ->selectRaw(
                        "markets.id, ?, fixtures.{$odds}, case when {$settled('fixtures.')} then (case when fixtures.winner = ? then 'won' else 'lost' end) end, ?, ?",
                        [$side, $side, $now, $now],
                    ),
            );
        }

        DB::table('bet_selections')->update(['market_outcome_id' => DB::raw(
            "(select market_outcomes.id from market_outcomes join markets on markets.id = market_outcomes.market_id
              where markets.fixture_id = bet_selections.fixture_id and markets.key = 'winner' and market_outcomes.key = bet_selections.side)"
        )]);
    }

    public function down(): void
    {
        // A pick on any market but the winner has no form without markets. Rather than delete players' bets, refuse
        // before changing anything; they have to be settled or removed on purpose first.
        if (DB::table('bet_selections')->whereNull('side')->exists()) {
            throw new RuntimeException('Cannot roll back markets: some bets have picks on markets other than the winner.');
        }

        Schema::table('bet_selections', function (Blueprint $table) {
            $table->dropForeign(['market_outcome_id']);
            $table->dropColumn('market_outcome_id');
            $table->string('side', 1)->nullable(false)->comment('')->change();
        });

        Schema::table('fixtures', function (Blueprint $table) {
            $table->dropColumn(['best_of', 'maps']);
        });

        Schema::dropIfExists('market_outcomes');
        Schema::dropIfExists('markets');
    }
};
