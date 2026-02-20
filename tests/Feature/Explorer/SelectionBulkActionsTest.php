<?php

namespace Tests\Feature\Explorer;

use App\Models\File;
use App\Models\Folder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class SelectionBulkActionsTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_bulk_trash_handles_files_and_folders_in_single_request(): void
    {
        $user = $this->createUser();
        $this->grantPermissions($user, ['files.delete', 'folders.delete']);

        $folder = $this->createPrivateFolder($user, [
            'name' => 'Bulk Folder',
            'path' => 'Bulk Folder',
        ]);
        $file = $this->createFile($folder, $user, [
            'original_name' => 'bulk-trash.txt',
        ]);

        $response = $this->actingAs($user)
            ->from(route('explorer.my'))
            ->withHeader('X-Idempotency-Key', 'selection-trash-1')
            ->post(route('selection.trash'), [
                'files' => [$file->public_id],
                'folders' => [$folder->public_id],
                'silent' => true,
            ]);

        $response->assertRedirect(route('explorer.my'));
        $this->assertTrue((bool) File::query()->find($file->id)?->is_deleted);
        $this->assertTrue((bool) Folder::query()->find($folder->id)?->is_deleted);
    }

    public function test_bulk_restore_handles_files_and_folders_in_single_request(): void
    {
        $user = $this->createUser();
        $this->grantPermissions($user, [
            'files.delete',
            'files.restore',
            'folders.delete',
            'folders.restore',
        ]);

        $folder = $this->createPrivateFolder($user, [
            'name' => 'Restore Folder',
            'path' => 'Restore Folder',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);
        $file = $this->createFile($folder, $user, [
            'original_name' => 'bulk-restore.txt',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->from(route('trash.index'))
            ->withHeader('X-Idempotency-Key', 'selection-restore-1')
            ->post(route('selection.restore'), [
                'files' => [$file->public_id],
                'folders' => [$folder->public_id],
                'silent' => true,
            ]);

        $response->assertRedirect(route('trash.index'));
        $this->assertFalse((bool) File::query()->find($file->id)?->is_deleted);
        $this->assertFalse((bool) Folder::query()->find($folder->id)?->is_deleted);
    }

    public function test_bulk_purge_handles_files_and_folders_in_single_request(): void
    {
        $user = $this->createUser();
        $this->grantPermissions($user, ['files.delete', 'folders.delete']);

        $folder = $this->createPrivateFolder($user, [
            'name' => 'Purge Folder',
            'path' => 'Purge Folder',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);
        $file = $this->createFile($folder, $user, [
            'original_name' => 'bulk-purge.txt',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->from(route('trash.index'))
            ->withHeader('X-Idempotency-Key', 'selection-purge-1')
            ->post(route('selection.purge'), [
                'files' => [$file->public_id],
                'folders' => [$folder->public_id],
                'silent' => true,
            ]);

        $response->assertRedirect(route('trash.index'));
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        $this->assertDatabaseMissing('folders', ['id' => $folder->id]);
    }
}

