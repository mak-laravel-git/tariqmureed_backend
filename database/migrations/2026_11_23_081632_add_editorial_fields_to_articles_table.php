<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('image_caption', 250)->nullable()->after('image');
            $table->string('image_credit', 150)->nullable()->after('image_caption');
            $table->string('social_image', 500)->nullable()->after('image_credit');
            $table->string('seo_title', 120)->nullable()->after('tags');
            $table->string('seo_description', 300)->nullable()->after('seo_title');
            $table->boolean('is_featured')->default(false)->after('status');
            $table->boolean('is_breaking')->default(false)->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['image_caption', 'image_credit', 'social_image', 'seo_title', 'seo_description', 'is_featured', 'is_breaking']);
        });
    }
};
