<?php

namespace Tests\Feature\Explorer;

use App\Jobs\ComputeChecksumJob;
use App\Jobs\VirusScanJob;
use App\Models\File;
use App\Services\ExplorerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class FileUploadFlowTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_upload_dispatches_checksum_and_antivirus_jobs_and_replays_idempotent_requests(): void
    {
        config(['antivirus.enabled' => true]);
        $disk = $this->fileStorageDisk();
        Storage::fake($disk);
        Queue::fake();

        $user = $this->createUser();
        $this->grantPermissions($user, ['files.upload']);
        $folder = $this->createPrivateFolder($user);

        $first = $this->actingAs($user)
            ->from(route('explorer.my'))
            ->withHeader('X-Idempotency-Key', 'upload-file-1')
            ->post(route('files.upload'), [
                'folder_id' => $folder->public_id,
                'file' => UploadedFile::fake()->create('proposal.txt', 20, 'text/plain'),
            ]);

        $first->assertRedirect(route('explorer.my'));

        $uploaded = File::query()
            ->where('folder_id', $folder->id)
            ->where('original_name', 'proposal.txt')
            ->where('is_deleted', false)
            ->firstOrFail();

        Storage::disk($disk)->assertExists($uploaded->storage_path);
        Queue::assertPushed(ComputeChecksumJob::class, 1);
        Queue::assertPushed(VirusScanJob::class, 1);

        $replay = $this->actingAs($user)
            ->from(route('explorer.my'))
            ->withHeader('X-Idempotency-Key', 'upload-file-1')
            ->post(route('files.upload'), [
                'folder_id' => $folder->public_id,
                'file' => UploadedFile::fake()->create('proposal.txt', 20, 'text/plain'),
            ]);

        $replay->assertRedirect(route('explorer.my'));

        $this->assertSame(1, File::query()->where('folder_id', $folder->id)->where('is_deleted', false)->count());
        Queue::assertPushed(ComputeChecksumJob::class, 1);
        Queue::assertPushed(VirusScanJob::class, 1);
    }

    public function test_upload_requires_idempotency_key_header(): void
    {
        Storage::fake($this->fileStorageDisk());
        Queue::fake();

        $user = $this->createUser();
        $this->grantPermissions($user, ['files.upload']);
        $folder = $this->createPrivateFolder($user);

        $response = $this->actingAs($user)->post(route('files.upload'), [
            'folder_id' => $folder->public_id,
            'file' => UploadedFile::fake()->create('missing-key.txt', 8, 'text/plain'),
        ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Missing X-Idempotency-Key header.',
            ]);

        $this->assertDatabaseCount('files', 0);
        Queue::assertNothingPushed();
    }

    public function test_upload_only_dispatches_checksum_when_antivirus_is_disabled(): void
    {
        config(['antivirus.enabled' => false]);
        Storage::fake($this->fileStorageDisk());
        Queue::fake();

        $user = $this->createUser();
        $this->grantPermissions($user, ['files.upload']);
        $folder = $this->createPrivateFolder($user);

        $response = $this->actingAs($user)
            ->from(route('explorer.my'))
            ->withHeader('X-Idempotency-Key', 'upload-file-no-av')
            ->post(route('files.upload'), [
                'folder_id' => $folder->public_id,
                'file' => UploadedFile::fake()->create('safe-file.txt', 15, 'text/plain'),
            ]);

        $response->assertRedirect(route('explorer.my'));

        Queue::assertPushed(ComputeChecksumJob::class, 1);
        Queue::assertNotPushed(VirusScanJob::class);
    }

    public function test_upload_route_alias_accepts_post_files_endpoint(): void
    {
        config(['antivirus.enabled' => true]);
        Storage::fake($this->fileStorageDisk());
        Queue::fake();

        $user = $this->createUser();
        $this->grantPermissions($user, ['files.upload']);
        $folder = $this->createPrivateFolder($user);

        $response = $this->actingAs($user)
            ->from(route('explorer.my'))
            ->withHeader('X-Idempotency-Key', 'upload-file-alias')
            ->post('/files', [
                'folder_id' => $folder->public_id,
                'file' => UploadedFile::fake()->create('alias-route.txt', 10, 'text/plain'),
            ]);

        $response->assertRedirect(route('explorer.my'));
        $this->assertDatabaseHas('files', [
            'folder_id' => $folder->id,
            'original_name' => 'alias-route.txt',
            'is_deleted' => false,
        ]);
        Queue::assertPushed(ComputeChecksumJob::class, 1);
        Queue::assertPushed(VirusScanJob::class, 1);
    }

    public function test_upload_into_shared_folder_sets_owner_to_uploader_not_folder_owner(): void
    {
        config(['antivirus.enabled' => true]);
        Storage::fake($this->fileStorageDisk());
        Queue::fake();

        $department = $this->createDepartment();
        $folderOwner = $this->createUser($department);
        $recipient = $this->createUser($department);

        $this->grantPermissions($folderOwner, ['share.manage', 'files.view']);
        $this->grantPermissions($recipient, ['files.upload']);

        $sharedFolder = $this->createPrivateFolder($folderOwner, [
            'name' => 'Shared Upload Target',
            'path' => 'Shared Upload Target',
            'visibility' => 'private',
            'department_id' => null,
        ]);

        $this->actingAs($folderOwner)->post(route('folders.share.users', $sharedFolder->public_id), [
            'shares' => [
                [
                    'user_id' => $recipient->id,
                    'can_view' => true,
                    'can_upload' => true,
                    'can_edit' => false,
                    'can_delete' => false,
                ],
            ],
        ])->assertRedirect();

        $response = $this->actingAs($recipient)
            ->from(route('explorer.shared'))
            ->withHeader('X-Idempotency-Key', 'upload-shared-folder-owner-1')
            ->post(route('files.upload'), [
                'folder_id' => $sharedFolder->public_id,
                'file' => UploadedFile::fake()->create('shared-upload.txt', 5, 'text/plain'),
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('files', [
            'folder_id' => $sharedFolder->id,
            'original_name' => 'shared-upload.txt',
            'owner_user_id' => $recipient->id,
            'is_deleted' => false,
        ]);
        $this->assertDatabaseMissing('files', [
            'folder_id' => $sharedFolder->id,
            'original_name' => 'shared-upload.txt',
            'owner_user_id' => $folderOwner->id,
            'is_deleted' => false,
        ]);
    }

    public function test_replace_upload_in_shared_folder_transfers_owner_to_uploader(): void
    {
        config(['antivirus.enabled' => true]);
        Storage::fake($this->fileStorageDisk());
        Queue::fake();

        $department = $this->createDepartment();
        $folderOwner = $this->createUser($department);
        $recipient = $this->createUser($department);

        $this->grantPermissions($folderOwner, ['share.manage', 'files.view']);
        $this->grantPermissions($recipient, ['files.upload']);

        $sharedFolder = $this->createPrivateFolder($folderOwner, [
            'name' => 'Shared Replace Target',
            'path' => 'Shared Replace Target',
            'visibility' => 'private',
            'department_id' => null,
        ]);

        $this->createFile($sharedFolder, $folderOwner, [
            'original_name' => 'replace-me.txt',
            'visibility' => 'private',
            'department_id' => null,
        ]);

        $this->actingAs($folderOwner)->post(route('folders.share.users', $sharedFolder->public_id), [
            'shares' => [
                [
                    'user_id' => $recipient->id,
                    'can_view' => true,
                    'can_upload' => true,
                    'can_edit' => false,
                    'can_delete' => false,
                ],
            ],
        ])->assertRedirect();

        $response = $this->actingAs($recipient)
            ->from(route('explorer.shared'))
            ->withHeader('X-Idempotency-Key', 'upload-shared-folder-owner-replace-1')
            ->post(route('files.upload'), [
                'folder_id' => $sharedFolder->public_id,
                'file' => UploadedFile::fake()->create('replace-me.txt', 6, 'text/plain'),
                'duplicate_mode' => 'replace',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('files', [
            'folder_id' => $sharedFolder->id,
            'original_name' => 'replace-me.txt',
            'owner_user_id' => $recipient->id,
            'is_deleted' => false,
        ]);
        $this->assertDatabaseMissing('files', [
            'folder_id' => $sharedFolder->id,
            'original_name' => 'replace-me.txt',
            'owner_user_id' => $folderOwner->id,
            'is_deleted' => false,
        ]);
    }

    public function test_shared_folder_contents_show_recipient_as_owner_for_uploaded_file(): void
    {
        config(['antivirus.enabled' => true]);
        Storage::fake($this->fileStorageDisk());
        Queue::fake();

        $department = $this->createDepartment();
        $folderOwner = $this->createUser($department);
        $recipient = $this->createUser($department);

        $this->grantPermissions($folderOwner, ['share.manage', 'files.view']);
        $this->grantPermissions($recipient, ['files.upload']);

        $sharedFolder = $this->createPrivateFolder($folderOwner, [
            'name' => 'Shared Owner Rendering Target',
            'path' => 'Shared Owner Rendering Target',
            'visibility' => 'private',
            'department_id' => null,
        ]);

        $this->actingAs($folderOwner)->post(route('folders.share.users', $sharedFolder->public_id), [
            'shares' => [
                [
                    'user_id' => $recipient->id,
                    'can_view' => true,
                    'can_upload' => true,
                    'can_edit' => false,
                    'can_delete' => false,
                ],
            ],
        ])->assertRedirect();

        $this->actingAs($recipient)
            ->from(route('explorer.shared'))
            ->withHeader('X-Idempotency-Key', 'upload-shared-folder-owner-payload-1')
            ->post(route('files.upload'), [
                'folder_id' => $sharedFolder->public_id,
                'file' => UploadedFile::fake()->create('recipient-owned.txt', 7, 'text/plain'),
            ])
            ->assertRedirect();

        $payload = app(ExplorerService::class)->folderContents(
            $recipient,
            $sharedFolder->fresh(),
            ['per_page' => 20],
        );
        $fileRow = collect($payload['files']->items())
            ->firstWhere('original_name', 'recipient-owned.txt');

        $this->assertNotNull($fileRow);
        $this->assertSame($recipient->email, data_get($fileRow, 'owner.email'));
        $this->assertSame('my_files', data_get($fileRow, 'source.scope'));
        $this->assertTrue((bool) data_get($fileRow, 'access.can_delete'));
    }
}
