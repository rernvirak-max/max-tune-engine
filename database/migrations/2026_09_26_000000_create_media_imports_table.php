<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->string('source_id', 64)->nullable()->after('source');
            $table->index(['user_id', 'source', 'source_id']);
        });

        Schema::create('media_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('video_id', 32);
            $table->string('status', 16)->default('queued'); // queued | downloading | processing | ready | failed
            $table->string('reason_code', 32)->nullable();
            $table->text('error_detail')->nullable(); // admin only
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('title')->nullable();
            $table->string('channel')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // "One active import per video" is enforced under the user row lock
            // (MariaDB has no partial unique indexes).
            $table->index(['user_id', 'video_id']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_imports');

        Schema::table('tracks', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'source', 'source_id']);
            $table->dropColumn('source_id');
        });
    }
};
