<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where recipes come from: a cook, blog or site. Shared by the household.
        Schema::create('chefs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('site_name')->nullable();
            $table->string('website')->nullable();
            $table->text('bio')->nullable();
            // Optional: which recipe's photo to use as the page hero.
            $table->foreignId('hero_recipe_id')->nullable();
            $table->timestamps();
        });

        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chef_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('source_url', 2048)->nullable()->index();
            $table->text('image_url')->nullable();
            $table->string('image_path')->nullable();
            $table->string('servings')->nullable();
            $table->unsignedSmallInteger('prep_minutes')->nullable();
            $table->unsignedSmallInteger('cook_minutes')->nullable();
            $table->unsignedSmallInteger('total_minutes')->nullable();
            $table->json('instructions')->nullable();
            $table->timestamps();
        });

        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            // The line as written ("5 cloves garlic, chopped") and the item it names ("garlic").
            $table->string('line', 500);
            $table->string('name');
            // The catalog product this ingredient is bought as; null = not in the catalog yet.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            // Things like water that never need buying.
            $table->boolean('skip')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('chefs');
    }
};
