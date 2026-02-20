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
        Schema::create('file_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')
                ->constrained('files')
                ->cascadeOnDelete();
            $table->integer('version_no');
            $table->string('stored_name', 255);
            $table->string('storage_path', 1024);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64)->nullable();
            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['file_id', 'version_no']);
            $table->index('file_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_versions');
    }
};

