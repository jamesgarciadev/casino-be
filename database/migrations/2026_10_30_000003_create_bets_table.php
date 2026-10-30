<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixture_id')->constrained()->cascadeOnDelete();
            $table->string('side', 1);
            $table->unsignedBigInteger('stake');
            $table->decimal('odds', 5, 2)->comment('Snapshot of the fixture odds when the bet was placed');
            $table->unsignedBigInteger('payout')->comment('stake x odds, rounded; credited only if won');
            $table->string('status', 16)->default('pending');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['fixture_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bets');
    }
};
