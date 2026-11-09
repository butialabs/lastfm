<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('albums', function (Blueprint $table) {
            $table->id();
            $table->string('artist_name');
            $table->string('name');
            $table->string('musicbrainz_id', 36)->nullable();
            $table->string('image_hash', 32)->nullable();
            $table->timestamps();

            $table->unique(['artist_name', 'name'], 'albums_unique_artist_name');
            $table->index('image_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('albums');
    }
};
