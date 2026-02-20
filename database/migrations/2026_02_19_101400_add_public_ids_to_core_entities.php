<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->addPublicId('employees');
        $this->addPublicId('users');
        $this->addPublicId('folders');
        $this->addPublicId('files');
        $this->addPublicId('share_links');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropPublicId('share_links');
        $this->dropPublicId('files');
        $this->dropPublicId('folders');
        $this->dropPublicId('users');
        $this->dropPublicId('employees');
    }

    private function addPublicId(string $table): void
    {
        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->uuid('public_id')->nullable()->after('id');
        });

        DB::table($table)
            ->orderBy('id')
            ->select('id')
            ->chunkById(500, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['public_id' => (string) Str::uuid()]);
                }
            });

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->uuid('public_id')->nullable(false)->change();
            $blueprint->unique('public_id');
        });
    }

    private function dropPublicId(string $table): void
    {
        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropUnique("{$table}_public_id_unique");
            $blueprint->dropColumn('public_id');
        });
    }
};

