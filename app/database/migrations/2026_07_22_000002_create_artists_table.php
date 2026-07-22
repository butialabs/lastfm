<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artists', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('lastfm_url');
            $table->string('musicbrainz_id', 36)->nullable();
            $table->string('image_hash', 32)->nullable();
            $table->timestamps();

            $table->index('image_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artists');
    }
};
