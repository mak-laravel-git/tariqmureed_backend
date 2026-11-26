<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixtures', function (Blueprint $table) {
            // Ball-by-ball (compacted), innings totals and extras, and officials/awards/series details.
            $table->json('balls')->nullable()->after('lineup');
            $table->json('scoreboards')->nullable()->after('balls');
            $table->json('details')->nullable()->after('scoreboards');
            $table->timestamp('centre_synced_at')->nullable()->after('details_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('fixtures', function (Blueprint $table) {
            $table->dropColumn(['balls', 'scoreboards', 'details', 'centre_synced_at']);
        });
    }
};
