<?php

namespace Tests\Feature\Explorer;

use App\Models\File;
use App\Models\Folder;
use App\Services\ExplorerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class TrashStructureTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_trash_root_only_lists_deleted_parent_folders_and_files_without_deleted_parent(): void
    {
        $user = $this->createUser();
        $explorerService = app(ExplorerService::class);

        $rootFolder = $this->createPrivateFolder($user, [
            'name' => 'Bold',
            'path' => 'Bold',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $childFolder = Folder::query()->create([
            'parent_id' => $rootFolder->id,
            'name' => 'Hotdog',
            'owner_user_id' => $user->id,
            'department_id' => null,
            'path' => 'Bold/Hotdog',
            'visibility' => 'private',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $fileInRootDeletedFolder = $this->createFile($rootFolder, $user, [
            'original_name' => 'inside-root.txt',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $fileInChildDeletedFolder = $this->createFile($childFolder, $user, [
            'original_name' => 'inside-child.txt',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $activeFolder = $this->createPrivateFolder($user, [
            'name' => 'Documents',
            'path' => 'Documents',
            'is_deleted' => false,
        ]);

        $fileWithActiveParent = $this->createFile($activeFolder, $user, [
            'original_name' => 'active-parent.txt',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $data = $explorerService->trash($user, ['per_page' => 50]);
        $folders = collect($data['folders']->items());
        $files = collect($data['files']->items());

        $this->assertTrue(
            $folders->contains(fn (Folder $folder): bool => $folder->id === $rootFolder->id),
        );
        $this->assertFalse(
            $folders->contains(fn (Folder $folder): bool => $folder->id === $childFolder->id),
        );

        $this->assertFalse(
            $files->contains(fn (File $file): bool => $file->id === $fileInRootDeletedFolder->id),
        );
        $this->assertFalse(
            $files->contains(fn (File $file): bool => $file->id === $fileInChildDeletedFolder->id),
        );
        $this->assertTrue(
            $files->contains(fn (File $file): bool => $file->id === $fileWithActiveParent->id),
        );

        /** @var Folder|null $rootRow */
        $rootRow = $folders->first(
            fn (Folder $folder): bool => $folder->id === $rootFolder->id,
        );
        $this->assertNotNull($rootRow);
        $this->assertSame(
            2,
            (int) $rootRow->getAttribute('trashed_files_count'),
        );
    }

    public function test_trash_folder_view_lists_deleted_children_and_direct_deleted_files(): void
    {
        $user = $this->createUser();
        $explorerService = app(ExplorerService::class);

        $rootFolder = $this->createPrivateFolder($user, [
            'name' => 'Bold',
            'path' => 'Bold',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $childFolder = Folder::query()->create([
            'parent_id' => $rootFolder->id,
            'name' => 'Hotdog',
            'owner_user_id' => $user->id,
            'department_id' => null,
            'path' => 'Bold/Hotdog',
            'visibility' => 'private',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $rootFile = $this->createFile($rootFolder, $user, [
            'original_name' => 'root.txt',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $nestedFile = $this->createFile($childFolder, $user, [
            'original_name' => 'nested.txt',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $data = $explorerService->trashFolderContents(
            $user,
            $rootFolder,
            ['per_page' => 50],
        );

        $this->assertSame($rootFolder->id, $data['folder']->id);
        $this->assertCount(1, $data['breadcrumbTrail']);
        $this->assertSame('Bold', $data['breadcrumbTrail'][0]['name']);
        $this->assertTrue(
            $data['children']->contains(
                fn (Folder $folder): bool => $folder->id === $childFolder->id,
            ),
        );

        /** @var Folder|null $childRow */
        $childRow = $data['children']->first(
            fn (Folder $folder): bool => $folder->id === $childFolder->id,
        );
        $this->assertNotNull($childRow);
        $this->assertSame(
            1,
            (int) $childRow->getAttribute('trashed_files_count'),
        );

        $files = collect($data['files']->items());
        $this->assertTrue(
            $files->contains(fn (File $file): bool => $file->id === $rootFile->id),
        );
        $this->assertFalse(
            $files->contains(fn (File $file): bool => $file->id === $nestedFile->id),
        );
    }

    public function test_restoring_a_deleted_file_restores_deleted_parent_folders_first(): void
    {
        $user = $this->createUser();
        $this->grantPermissions($user, ['files.delete', 'files.restore']);

        $rootFolder = $this->createPrivateFolder($user, [
            'name' => 'Bold',
            'path' => 'Bold',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $childFolder = Folder::query()->create([
            'parent_id' => $rootFolder->id,
            'name' => 'Hotdog',
            'owner_user_id' => $user->id,
            'department_id' => null,
            'path' => 'Bold/Hotdog',
            'visibility' => 'private',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $file = $this->createFile($childFolder, $user, [
            'original_name' => 'nested.txt',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->from(route('trash.folders.show', $rootFolder->public_id))
            ->withHeader('X-Idempotency-Key', 'restore-file-parent-chain-1')
            ->post(route('files.restore', $file->public_id));

        $response
            ->assertRedirect(route('folders.show', $childFolder->public_id))
            ->assertSessionHas('status', 'File restored to Hotdog.');
        $this->assertDatabaseHas('folders', [
            'id' => $rootFolder->id,
            'is_deleted' => false,
        ]);
        $this->assertDatabaseHas('folders', [
            'id' => $childFolder->id,
            'is_deleted' => false,
        ]);
        $this->assertDatabaseHas('files', [
            'id' => $file->id,
            'is_deleted' => false,
            'folder_id' => $childFolder->id,
        ]);
    }

    public function test_restoring_a_deleted_folder_restores_deleted_parent_folders_first(): void
    {
        $user = $this->createUser();
        $this->grantPermissions($user, ['folders.delete', 'folders.restore']);

        $rootFolder = $this->createPrivateFolder($user, [
            'name' => 'Bold',
            'path' => 'Bold',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $childFolder = Folder::query()->create([
            'parent_id' => $rootFolder->id,
            'name' => 'Hotdog',
            'owner_user_id' => $user->id,
            'department_id' => null,
            'path' => 'Bold/Hotdog',
            'visibility' => 'private',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->from(route('trash.folders.show', $rootFolder->public_id))
            ->withHeader('X-Idempotency-Key', 'restore-folder-parent-chain-1')
            ->post(route('folders.restore', $childFolder->public_id));

        $response
            ->assertRedirect(route('folders.show', $childFolder->public_id))
            ->assertSessionHas('status', 'Folder restored: Hotdog.');
        $this->assertDatabaseHas('folders', [
            'id' => $rootFolder->id,
            'is_deleted' => false,
        ]);
        $this->assertDatabaseHas('folders', [
            'id' => $childFolder->id,
            'is_deleted' => false,
        ]);
    }
}
