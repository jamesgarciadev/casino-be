<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bettable matches (exposed as `Match` in GraphQL; `match` is reserved in PHP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixtures', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16)->index();
            $table->unsignedBigInteger('external_id')->nullable()->comment('PandaScore match id the matchup was taken from');
            $table->string('game', 40)->index()->comment('PandaScore videogame slug, e.g. cs-go');
            $table->string('game_name', 80);
            $table->string('tournament');
            $table->string('team_a');
            $table->string('team_b');
            $table->string('team_a_acronym', 20)->nullable();
            $table->string('team_b_acronym', 20)->nullable();
            $table->unsignedSmallInteger('score_a')->default(0);
            $table->unsignedSmallInteger('score_b')->default(0);
            $table->decimal('odds_a', 5, 2);
            $table->decimal('odds_b', 5, 2);
            $table->timestamp('starts_at')->index();
            $table->string('state', 16)->default('upcoming')->index();
            $table->string('winner', 1)->nullable();
            $table->string('stream_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixtures');
    }
};
