<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('grid_source', 10)->default('artist')->after('language');
            $table->unsignedTinyInteger('grid_size')->default(5)->after('grid_source');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['grid_source', 'grid_size']);
        });
    }
};
