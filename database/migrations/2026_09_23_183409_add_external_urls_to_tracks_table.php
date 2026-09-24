<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->text('external_stream_url')->nullable()->after('import_mode');
            $table->text('external_cover_url')->nullable()->after('external_stream_url');
            $table->unique(['user_id', 'source', 'external_id'], 'tracks_user_source_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropUnique('tracks_user_source_external_unique');
            $table->dropColumn(['external_stream_url', 'external_cover_url']);
        });
    }
};
