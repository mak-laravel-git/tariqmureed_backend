<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('league_id')->index();
            $table->unsignedBigInteger('stage_id')->nullable();
            $table->unsignedBigInteger('team_id');
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedSmallInteger('played')->default(0);
            $table->unsignedSmallInteger('won')->default(0);
            $table->unsignedSmallInteger('lost')->default(0);
            $table->unsignedSmallInteger('draw')->default(0);
            $table->unsignedSmallInteger('noresult')->default(0);
            $table->integer('points')->default(0);
            $table->decimal('net_run_rate', 8, 3)->default(0);
            $table->json('recent_form')->nullable();
            $table->timestamps();

            $table->unique(['season_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standings');
    }
};
