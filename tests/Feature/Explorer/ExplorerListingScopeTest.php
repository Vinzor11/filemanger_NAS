<?php

namespace Tests\Feature\Explorer;

use App\Services\ExplorerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class ExplorerListingScopeTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_my_files_root_excludes_files_stored_inside_folders(): void
    {
        $user = $this->createUser();
        $folder = $this->createPrivateFolder($user, [
            'name' => 'Folder 1',
            'path' => 'Folder 1',
        ]);
        $this->createFile($folder, $user, [
            'original_name' => 'inside-folder.txt',
        ]);

        $data = app(ExplorerService::class)->myFiles($user, []);

        $this->assertCount(1, $data['folders']);
        $this->assertCount(0, $data['files']->items());
    }

    public function test_department_files_root_excludes_files_stored_inside_folders(): void
    {
        $user = $this->createUser();
        $folder = $this->createDepartmentFolder($user->employee->department, [
            'name' => 'Dept Folder',
            'path' => 'Dept Folder',
        ]);
        $this->createFile($folder, $user, [
            'original_name' => 'inside-department-folder.txt',
        ]);

        $data = app(ExplorerService::class)->departmentFiles($user, []);

        $this->assertCount(1, $data['folders']);
        $this->assertCount(0, $data['files']->items());
    }
}

