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
        Schema::create('file_tags', function (Blueprint $table) {
            $table->foreignId('file_id')
                ->constrained('files')
                ->cascadeOnDelete();
            $table->foreignId('tag_id')
                ->constrained('tags')
                ->cascadeOnDelete();

            $table->primary(['file_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_tags');
    }
};

