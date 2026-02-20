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
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('scope', 80);
            $table->string('idempotency_key', 120);
            $table->char('request_hash', 64);
            $table->enum('status', ['in_progress', 'completed', 'failed'])->default('in_progress');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->longText('response_body')->nullable();
            $table->string('resource_type', 80)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['actor_user_id', 'scope', 'idempotency_key'], 'idempotency_actor_scope_key_unique');
            $table->index(['scope', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};

