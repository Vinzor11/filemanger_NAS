<?php

namespace Tests\Feature\Explorer;

use App\Models\File;
use App\Models\Folder;
use Illuminate\Support\Facades\Storage;
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

    public function test_bulk_move_handles_files_and_folders_in_single_request(): void
    {
        $user = $this->createUser();
        $this->grantPermissions($user, ['files.update', 'folders.update', 'files.upload']);

        $folderToMove = $this->createPrivateFolder($user, [
            'name' => 'Move Folder',
            'path' => 'Move Folder',
        ]);
        $fileSourceFolder = $this->createPrivateFolder($user, [
            'name' => 'File Source',
            'path' => 'File Source',
        ]);
        $fileToMove = $this->createFile($fileSourceFolder, $user, [
            'original_name' => 'bulk-move.txt',
        ]);
        $destination = $this->createPrivateFolder($user, [
            'name' => 'Move Destination',
            'path' => 'Move Destination',
        ]);

        $response = $this->actingAs($user)
            ->from(route('explorer.my'))
            ->withHeader('X-Idempotency-Key', 'selection-move-1')
            ->post(route('selection.move'), [
                'files' => [$fileToMove->public_id],
                'folders' => [$folderToMove->public_id],
                'destination_folder_id' => $destination->public_id,
                'silent' => true,
            ]);

        $response->assertRedirect(route('explorer.my'));
        $this->assertSame($destination->id, (int) File::query()->find($fileToMove->id)?->folder_id);
        $this->assertSame($destination->id, (int) Folder::query()->find($folderToMove->id)?->parent_id);
    }

    public function test_bulk_download_handles_files_and_folders_in_single_request(): void
    {
        $user = $this->createUser();
        $this->grantPermissions($user, ['files.view', 'files.download']);

        $selectedFolder = $this->createPrivateFolder($user, [
            'name' => 'Download Folder',
            'path' => 'Download Folder',
        ]);
        $folderFile = $this->createFile($selectedFolder, $user, [
            'original_name' => 'folder-file.txt',
        ]);
        $childFolder = $this->createPrivateFolder($user, [
            'name' => 'Nested',
            'parent_id' => $selectedFolder->id,
            'path' => 'Download Folder/Nested',
        ]);
        $nestedFile = $this->createFile($childFolder, $user, [
            'original_name' => 'nested-file.txt',
        ]);
        $standaloneFolder = $this->createPrivateFolder($user, [
            'name' => 'Standalone',
            'path' => 'Standalone',
        ]);
        $standaloneFile = $this->createFile($standaloneFolder, $user, [
            'original_name' => 'standalone-file.txt',
        ]);

        Storage::disk($folderFile->storage_disk)->put($folderFile->storage_path, 'folder');
        Storage::disk($nestedFile->storage_disk)->put($nestedFile->storage_path, 'nested');
        Storage::disk($standaloneFile->storage_disk)->put($standaloneFile->storage_path, 'standalone');

        $response = $this->actingAs($user)->get(route('selection.download', [
            'files' => [$standaloneFile->public_id],
            'folders' => [$selectedFolder->public_id],
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
    }

    public function test_bulk_share_users_handles_files_and_folders_in_single_request(): void
    {
        $department = $this->createDepartment();
        $actor = $this->createUser($department);
        $target = $this->createUser($department);
        $this->grantPermissions($actor, ['share.manage', 'files.view']);

        $folderToShare = $this->createPrivateFolder($actor, [
            'name' => 'Folder To Share',
            'path' => 'Folder To Share',
        ]);
        $fileSourceFolder = $this->createPrivateFolder($actor, [
            'name' => 'File Share Source',
            'path' => 'File Share Source',
        ]);
        $fileToShare = $this->createFile($fileSourceFolder, $actor, [
            'original_name' => 'bulk-share-users.txt',
        ]);

        $response = $this->actingAs($actor)
            ->from(route('explorer.my'))
            ->withHeader('X-Idempotency-Key', 'selection-share-users-1')
            ->post(route('selection.share.users'), [
                'files' => [$fileToShare->public_id],
                'folders' => [$folderToShare->public_id],
                'shares' => [
                    [
                        'user_id' => $target->id,
                        'can_view' => true,
                        'can_download' => false,
                        'can_edit' => true,
                        'can_delete' => false,
                    ],
                ],
                'silent' => true,
            ]);

        $response->assertRedirect(route('explorer.my'));
        $this->assertDatabaseHas('file_permissions', [
            'file_id' => $fileToShare->id,
            'user_id' => $target->id,
            'can_view' => true,
            'can_edit' => true,
        ]);
        $this->assertDatabaseHas('folder_permissions', [
            'folder_id' => $folderToShare->id,
            'user_id' => $target->id,
            'can_view' => true,
            'can_edit' => true,
        ]);
    }

    public function test_bulk_share_department_handles_files_and_folders_in_single_request(): void
    {
        $department = $this->createDepartment();
        $actor = $this->createUser($department);
        $peer = $this->createUser($department);
        $this->grantPermissions($actor, ['share.manage', 'files.view']);
        $this->grantPermissions($peer, ['files.view']);

        $folderToShare = $this->createPrivateFolder($actor, [
            'name' => 'Department Folder Share',
            'path' => 'Department Folder Share',
        ]);
        $fileSourceFolder = $this->createPrivateFolder($actor, [
            'name' => 'Department File Source',
            'path' => 'Department File Source',
        ]);
        $fileToShare = $this->createFile($fileSourceFolder, $actor, [
            'original_name' => 'bulk-share-department.txt',
            'visibility' => 'private',
            'department_id' => null,
        ]);

        $response = $this->actingAs($actor)
            ->from(route('explorer.my'))
            ->withHeader('X-Idempotency-Key', 'selection-share-department-1')
            ->post(route('selection.share.department'), [
                'files' => [$fileToShare->public_id],
                'folders' => [$folderToShare->public_id],
                'can_view' => true,
                'can_edit' => true,
                'can_delete' => false,
                'silent' => true,
            ]);

        $response->assertRedirect(route('explorer.my'));
        $this->assertDatabaseHas('files', [
            'id' => $fileToShare->id,
            'visibility' => 'department',
            'department_id' => $actor->employee?->department_id,
        ]);
        $this->assertDatabaseHas('folders', [
            'id' => $folderToShare->id,
            'visibility' => 'shared',
        ]);
        $this->assertDatabaseHas('file_permissions', [
            'file_id' => $fileToShare->id,
            'user_id' => $peer->id,
            'can_view' => true,
            'can_edit' => true,
        ]);
        $this->assertDatabaseHas('folder_permissions', [
            'folder_id' => $folderToShare->id,
            'user_id' => $peer->id,
            'can_view' => true,
            'can_edit' => true,
            'can_upload' => true,
        ]);
    }
}
