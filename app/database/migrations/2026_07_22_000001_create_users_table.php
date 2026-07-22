<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('protocol', 20);
            $table->string('instance');
            $table->string('username');
            $table->string('did')->nullable();
            $table->text('password')->nullable();
            $table->text('token')->nullable();
            $table->string('lastfm_username')->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->string('time', 20)->nullable();
            $table->string('timezone', 100)->nullable();
            $table->string('language', 10)->default('en');
            $table->string('status', 20)->default('ACTIVE');
            $table->text('callback')->nullable();
            $table->text('social_message')->nullable();
            $table->string('social_montage')->nullable();
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamps();

            $table->unique(['protocol', 'instance', 'username'], 'users_unique_account');
            $table->index('status', 'users_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
