<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advertisements', function (Blueprint $table) {
            $table->string('type', 20)->default('image')->after('advertiser');
            $table->string('image')->nullable()->after('type');
            $table->string('link_url', 500)->nullable()->after('image');
            $table->string('adsense_slot', 30)->nullable()->after('link_url');
            $table->timestamp('starts_at')->nullable()->after('adsense_slot');
            $table->timestamp('ends_at')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('advertisements', function (Blueprint $table) {
            $table->dropColumn(['type', 'image', 'link_url', 'adsense_slot', 'starts_at', 'ends_at']);
        });
    }
};
