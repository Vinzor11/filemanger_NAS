<?php

namespace Tests\Feature\Explorer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;
use ZipArchive;

class FolderDownloadTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_folder_download_returns_zip_with_nested_files(): void
    {
        Storage::fake($this->fileStorageDisk());

        $user = $this->createUser();
        $this->grantPermissions($user, ['files.view', 'files.download']);

        $rootFolder = $this->createPrivateFolder($user, [
            'name' => 'ProjectRoot',
            'path' => 'ProjectRoot',
        ]);
        $childFolder = $this->createPrivateFolder($user, [
            'name' => 'Docs',
            'parent_id' => $rootFolder->id,
            'path' => 'ProjectRoot/Docs',
        ]);

        $rootFile = $this->createFile($rootFolder, $user, [
            'original_name' => 'readme.txt',
            'stored_name' => 'readme.txt',
            'storage_path' => "private/{$user->public_id}/downloads/readme.txt",
        ]);
        $childFile = $this->createFile($childFolder, $user, [
            'original_name' => 'spec.txt',
            'stored_name' => 'spec.txt',
            'storage_path' => "private/{$user->public_id}/downloads/spec.txt",
        ]);

        Storage::disk($this->fileStorageDisk())->put($rootFile->storage_path, 'Root file');
        Storage::disk($this->fileStorageDisk())->put($childFile->storage_path, 'Nested file');

        $response = $this->actingAs($user)->get(route('folders.download', $rootFolder->public_id));

        $response->assertOk();
        $response->assertDownload('ProjectRoot.zip');
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);

        $archivePath = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;

        $this->assertTrue($zip->open($archivePath));
        $this->assertNotFalse($zip->locateName('ProjectRoot/readme.txt'));
        $this->assertNotFalse($zip->locateName('ProjectRoot/Docs/spec.txt'));
        $this->assertSame('Root file', $zip->getFromName('ProjectRoot/readme.txt'));
        $this->assertSame('Nested file', $zip->getFromName('ProjectRoot/Docs/spec.txt'));
        $zip->close();
    }

    public function test_folder_download_is_forbidden_when_user_cannot_download_all_files(): void
    {
        Storage::fake($this->fileStorageDisk());

        $department = $this->createDepartment();
        $owner = $this->createUser($department);
        $viewer = $this->createUser($department);

        $this->grantPermissions($owner, ['files.view', 'files.download']);
        $this->grantPermissions($viewer, ['files.view']);

        $departmentFolder = $this->createDepartmentFolder($department, [
            'name' => 'DepartmentRoot',
            'path' => 'DepartmentRoot',
            'visibility' => 'department',
        ]);
        $file = $this->createFile($departmentFolder, $owner, [
            'original_name' => 'department-note.txt',
            'stored_name' => 'department-note.txt',
            'storage_path' => "department/{$department->code}/department-note.txt",
            'visibility' => 'department',
            'department_id' => $department->id,
        ]);
        Storage::disk($this->fileStorageDisk())->put($file->storage_path, 'department');

        $this->assertTrue($viewer->can('view', $departmentFolder->fresh()));
        $this->assertFalse($viewer->can('download', $file->fresh()));

        $response = $this->actingAs($viewer)->get(route('folders.download', $departmentFolder->public_id));

        $response->assertForbidden();
    }
}
