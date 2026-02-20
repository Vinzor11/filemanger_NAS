<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->safeStatement(
            'ALTER TABLE folders ADD CONSTRAINT chk_folders_scope_xor CHECK ((owner_user_id IS NOT NULL AND department_id IS NULL) OR (owner_user_id IS NULL AND department_id IS NOT NULL))'
        );
        $this->safeStatement(
            'ALTER TABLE files ADD CONSTRAINT chk_files_size_positive CHECK (size_bytes > 0)'
        );
        $this->safeStatement(
            'ALTER TABLE file_versions ADD CONSTRAINT chk_file_versions_no_positive CHECK (version_no > 0)'
        );
        $this->safeStatement(
            'ALTER TABLE share_links ADD CONSTRAINT chk_share_links_max_downloads CHECK (max_downloads IS NULL OR max_downloads > 0)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->safeStatement('ALTER TABLE folders DROP CONSTRAINT chk_folders_scope_xor');
        $this->safeStatement('ALTER TABLE files DROP CONSTRAINT chk_files_size_positive');
        $this->safeStatement('ALTER TABLE file_versions DROP CONSTRAINT chk_file_versions_no_positive');
        $this->safeStatement('ALTER TABLE share_links DROP CONSTRAINT chk_share_links_max_downloads');
    }

    private function safeStatement(string $sql): void
    {
        try {
            DB::statement($sql);
        } catch (\Throwable) {
            // Intentionally ignore for engines that don't support named constraint DDL variants.
        }
    }
};

