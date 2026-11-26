<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_rankings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->string('format', 8);
            $table->string('gender', 8);
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedInteger('matches')->default(0);
            $table->unsignedInteger('points')->default(0);
            $table->unsignedSmallInteger('rating')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'format', 'gender']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_rankings');
    }
};
