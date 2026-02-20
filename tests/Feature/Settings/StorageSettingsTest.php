<?php

namespace Tests\Feature\Settings;

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class StorageSettingsTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_user_with_manage_permission_can_update_storage_disk(): void
    {
        config(['antivirus.enabled' => false]);
        Storage::fake('local');
        Storage::fake('nas');

        $user = $this->createUser();
        $this->grantPermissions($user, ['settings.manage', 'files.upload']);
        $folder = $this->createPrivateFolder($user);

        $response = $this->actingAs($user)
            ->from('/settings/storage')
            ->put('/settings/storage', [
                'storage_disk' => 'nas',
            ]);

        $response->assertRedirect('/settings/storage');
        $this->assertDatabaseHas('app_settings', [
            'key' => 'file_storage_disk',
            'value' => 'nas',
        ]);

        $this->actingAs($user)
            ->withHeader('X-Idempotency-Key', 'settings-storage-upload-1')
            ->post('/files/upload', [
                'folder_id' => $folder->public_id,
                'file' => UploadedFile::fake()->create('from-settings.txt', 4, 'text/plain'),
            ])
            ->assertRedirect();

        $file = File::query()
            ->where('folder_id', $folder->id)
            ->where('original_name', 'from-settings.txt')
            ->firstOrFail();

        $this->assertSame('nas', $file->storage_disk);
        Storage::disk('nas')->assertExists($file->storage_path);
    }

    public function test_user_without_manage_permission_cannot_access_storage_settings(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->get('/settings/storage')
            ->assertForbidden();
    }
}
