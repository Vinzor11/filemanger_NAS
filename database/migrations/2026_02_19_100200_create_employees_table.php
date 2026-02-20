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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_no', 30)->unique();
            $table->foreignId('department_id')
                ->constrained('departments')
                ->restrictOnDelete();
            $table->foreignId('position_id')
                ->nullable()
                ->constrained('positions')
                ->nullOnDelete();
            $table->string('position_title', 120)->nullable();
            $table->string('first_name', 80);
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80);
            $table->string('email', 150)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->enum('status', ['active', 'inactive', 'resigned'])->default('active');
            $table->date('hired_at')->nullable();
            $table->timestamps();

            $table->index(['department_id', 'status']);
            $table->index('position_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};

