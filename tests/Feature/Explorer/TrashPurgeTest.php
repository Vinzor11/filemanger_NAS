<?php

namespace Tests\Feature\Explorer;

use App\Models\File;
use App\Models\FileVersion;
use App\Models\Folder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class TrashPurgeTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_deleted_file_can_be_deleted_forever_and_removes_storage_objects(): void
    {
        $disk = $this->fileStorageDisk();
        Storage::fake($disk);

        $user = $this->createUser();
        $this->grantPermissions($user, ['files.delete']);
        $folder = $this->createPrivateFolder($user, [
            'path' => 'My Files',
        ]);

        $file = $this->createFile($folder, $user, [
            'original_name' => 'to-purge.txt',
            'storage_path' => 'private/test/purge/current.txt',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        FileVersion::query()->create([
            'file_id' => $file->id,
            'version_no' => 1,
            'stored_name' => 'version-1.txt',
            'storage_path' => 'private/test/purge/version-1.txt',
            'size_bytes' => 50,
            'checksum_sha256' => null,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        Storage::disk($disk)->put($file->storage_path, 'current');
        Storage::disk($disk)->put('private/test/purge/version-1.txt', 'old');

        $response = $this->actingAs($user)
            ->from(route('trash.index'))
            ->withHeader('X-Idempotency-Key', 'purge-file-1')
            ->delete(route('files.purge', $file->public_id));

        $response->assertRedirect(route('trash.index'));
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        $this->assertDatabaseMissing('file_versions', ['file_id' => $file->id]);
        Storage::disk($disk)->assertMissing('private/test/purge/current.txt');
        Storage::disk($disk)->assertMissing('private/test/purge/version-1.txt');
    }

    public function test_deleted_folder_subtree_can_be_deleted_forever_with_nested_files(): void
    {
        $disk = $this->fileStorageDisk();
        Storage::fake($disk);

        $user = $this->createUser();
        $this->grantPermissions($user, ['folders.delete']);

        $root = $this->createPrivateFolder($user, [
            'name' => 'Root',
            'path' => 'Root',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $child = Folder::query()->create([
            'parent_id' => $root->id,
            'name' => 'Child',
            'owner_user_id' => $user->id,
            'department_id' => null,
            'path' => 'Root/Child',
            'visibility' => 'private',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $file = $this->createFile($child, $user, [
            'original_name' => 'nested.txt',
            'storage_path' => 'private/test/purge/nested-current.txt',
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        FileVersion::query()->create([
            'file_id' => $file->id,
            'version_no' => 1,
            'stored_name' => 'nested-version.txt',
            'storage_path' => 'private/test/purge/nested-version.txt',
            'size_bytes' => 25,
            'checksum_sha256' => null,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        Storage::disk($disk)->put('private/test/purge/nested-current.txt', 'a');
        Storage::disk($disk)->put('private/test/purge/nested-version.txt', 'b');

        $response = $this->actingAs($user)
            ->from(route('trash.index'))
            ->withHeader('X-Idempotency-Key', 'purge-folder-1')
            ->delete(route('folders.purge', $root->public_id));

        $response->assertRedirect(route('trash.index'));
        $this->assertDatabaseMissing('folders', ['id' => $root->id]);
        $this->assertDatabaseMissing('folders', ['id' => $child->id]);
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        Storage::disk($disk)->assertMissing('private/test/purge/nested-current.txt');
        Storage::disk($disk)->assertMissing('private/test/purge/nested-version.txt');
    }
}
