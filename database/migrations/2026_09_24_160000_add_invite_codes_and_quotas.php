<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('storage_quota_bytes')
                ->nullable()
                ->after('storage_used_bytes');
        });

        Schema::create('invite_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('label')->nullable();
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('invite_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invite_code_id')->constrained('invite_codes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['invite_code_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_redemptions');
        Schema::dropIfExists('invite_codes');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('storage_quota_bytes');
        });
    }
};
