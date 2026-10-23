<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An imported recipe stays a draft until its ingredients are picked in the import wizard.
        Schema::table('recipes', function (Blueprint $table) {
            $table->boolean('draft')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('draft');
        });
    }
};
