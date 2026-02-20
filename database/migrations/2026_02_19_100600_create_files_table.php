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
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')
                ->constrained('folders')
                ->restrictOnDelete();
            $table->foreignId('owner_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();
            $table->string('original_name', 255);
            $table->string('stored_name', 255);
            $table->string('extension', 20)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64)->nullable();
            $table->string('storage_disk', 50)->default('local');
            $table->string('storage_path', 1024);
            $table->enum('visibility', ['private', 'department', 'shared'])->default('private');
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['folder_id', 'is_deleted']);
            $table->index(['owner_user_id', 'is_deleted']);
            $table->index(['department_id', 'is_deleted']);
            $table->index('checksum_sha256');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
