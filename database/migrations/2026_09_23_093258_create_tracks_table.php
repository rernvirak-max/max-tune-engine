<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('artist_name')->nullable();
            $table->string('album_name')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('storage_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('visibility', 32)->default('private');
            $table->string('source', 32)->default('upload'); // upload | jamendo | …
            $table->string('external_id')->nullable();
            $table->string('license_url')->nullable();
            $table->string('import_mode', 16)->nullable(); // stored | linked
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'title']);
            $table->index(['user_id', 'artist_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};
