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
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('password', 'password_hash');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('employee_id')
                ->nullable()
                ->unique()
                ->after('id')
                ->constrained('employees')
                ->nullOnDelete();
            $table->string('email', 150)->nullable()->change();
            $table->enum('status', ['pending', 'active', 'rejected', 'blocked'])
                ->default('pending')
                ->after('password_hash');
            $table->foreignId('approved_by')
                ->nullable()
                ->after('status')
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')
                ->nullable()
                ->after('approved_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('rejected_at')->nullable()->after('rejected_by');
            $table->string('rejection_reason', 255)->nullable()->after('rejected_at');
            $table->dateTime('last_login_at')->nullable()->after('rejection_reason');

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropUnique('users_employee_id_unique');
            $table->dropIndex('users_status_created_at_index');

            $table->dropColumn([
                'employee_id',
                'status',
                'approved_by',
                'approved_at',
                'rejected_by',
                'rejected_at',
                'rejection_reason',
                'last_login_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 255)->nullable(false)->change();
            $table->renameColumn('password_hash', 'password');
        });
    }
};

