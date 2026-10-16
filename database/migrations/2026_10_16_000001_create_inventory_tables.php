<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });
        Schema::create('setup_locks', function (Blueprint $table) {
            $table->string('key')->primary();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('category')->index();
            $table->string('name')->index();
            $table->text('image_url')->nullable();
            $table->string('image_path')->nullable();
            $table->string('type')->nullable();
            $table->string('size')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('target_quantity')->default(1);
            $table->string('location')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'product_id']);
        });
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('checked')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('stocks');
        Schema::dropIfExists('products');
        Schema::dropIfExists('setup_locks');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
