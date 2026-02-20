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
        Schema::create('share_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')
                ->constrained('files')
                ->cascadeOnDelete();
            $table->char('token', 64)->unique();
            $table->dateTime('expires_at')->nullable();
            $table->integer('max_downloads')->nullable();
            $table->integer('download_count')->default(0);
            $table->string('password_hash', 255)->nullable();
            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index('file_id');
            $table->index('expires_at');
            $table->index('revoked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('share_links');
    }
};

