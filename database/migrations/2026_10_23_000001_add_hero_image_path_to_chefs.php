<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An uploaded page photo; when set it wins over the recipe photos.
        Schema::table('chefs', function (Blueprint $table) {
            $table->string('hero_image_path')->nullable()->after('hero_recipe_id');
        });
    }

    public function down(): void
    {
        Schema::table('chefs', function (Blueprint $table) {
            $table->dropColumn('hero_image_path');
        });
    }
};
