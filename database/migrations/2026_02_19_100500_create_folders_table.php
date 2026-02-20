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
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('folders')
                ->nullOnDelete();
            $table->string('name', 255);
            $table->foreignId('owner_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();
            $table->string('path', 1024)->nullable();
            $table->enum('visibility', ['private', 'department', 'shared'])->default('private');
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['parent_id', 'name', 'owner_user_id', 'department_id', 'is_deleted'],
                'folders_parent_name_scope_deleted_unique'
            );
            $table->index('parent_id');
            $table->index(['owner_user_id', 'is_deleted']);
            $table->index(['department_id', 'is_deleted']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};

