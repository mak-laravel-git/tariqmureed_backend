<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixtures', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('slug')->unique();
            $table->unsignedBigInteger('league_id')->index();
            $table->unsignedBigInteger('season_id')->nullable()->index();
            $table->unsignedBigInteger('stage_id')->nullable();
            $table->string('round')->nullable();
            $table->unsignedBigInteger('local_team_id')->index();
            $table->unsignedBigInteger('visitor_team_id')->index();
            $table->unsignedBigInteger('venue_id')->nullable();
            $table->dateTime('starting_at')->index();
            $table->string('type', 30)->nullable();
            $table->string('status', 30)->default('NS')->index();
            $table->boolean('is_live')->default(false)->index();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('toss_won_team_id')->nullable();
            $table->unsignedBigInteger('winner_team_id')->nullable();
            $table->string('elected', 20)->nullable();
            $table->unsignedBigInteger('man_of_match_id')->nullable();
            // Raw SportMonks scoreboard payloads, mapped to the site contract when read.
            $table->json('runs')->nullable();
            $table->json('batting')->nullable();
            $table->json('bowling')->nullable();
            $table->json('lineup')->nullable();
            $table->timestamp('details_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixtures');
    }
};
