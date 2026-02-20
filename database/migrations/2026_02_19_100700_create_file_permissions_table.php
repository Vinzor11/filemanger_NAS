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
        Schema::create('file_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')
                ->constrained('files')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->boolean('can_view')->default(true);
            $table->boolean('can_download')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(['file_id', 'user_id']);
            $table->index('user_id');
            $table->index('file_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_permissions');
    }
};

