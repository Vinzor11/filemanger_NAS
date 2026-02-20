<?php

namespace Tests\Unit\Policies;

use App\Models\FilePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class FilePolicyTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_owner_with_global_permissions_can_view_and_download(): void
    {
        $owner = $this->createUser();
        $this->grantPermissions($owner, ['files.view', 'files.download']);

        $folder = $this->createPrivateFolder($owner);
        $file = $this->createFile($folder, $owner);

        $this->assertTrue($owner->can('view', $file));
        $this->assertTrue($owner->can('download', $file));
    }

    public function test_same_department_user_can_view_and_download_department_file(): void
    {
        $department = $this->createDepartment();
        $owner = $this->createUser($department);
        $viewer = $this->createUser($department);
        $this->grantPermissions($viewer, ['files.view', 'files.download']);

        $folder = $this->createDepartmentFolder($department, [
            'visibility' => 'department',
        ]);
        $file = $this->createFile($folder, $owner, [
            'department_id' => $department->id,
            'visibility' => 'department',
        ]);

        $this->assertTrue($viewer->can('view', $file));
        $this->assertTrue($viewer->can('download', $file));
    }

    public function test_explicit_file_permission_grants_cross_department_access(): void
    {
        $ownerDepartment = $this->createDepartment(['code' => 'OWN-001']);
        $viewerDepartment = $this->createDepartment(['code' => 'OTH-001']);

        $owner = $this->createUser($ownerDepartment);
        $viewer = $this->createUser($viewerDepartment);
        $this->grantPermissions($viewer, ['files.view', 'files.download']);

        $folder = $this->createPrivateFolder($owner, ['visibility' => 'private']);
        $file = $this->createFile($folder, $owner, ['visibility' => 'private']);

        FilePermission::query()->create([
            'file_id' => $file->id,
            'user_id' => $viewer->id,
            'can_view' => true,
            'can_download' => true,
            'can_edit' => false,
            'can_delete' => false,
            'created_by' => $owner->id,
        ]);

        $this->assertTrue($viewer->can('view', $file));
        $this->assertTrue($viewer->can('download', $file));
    }

    public function test_deleted_file_is_not_viewable_even_for_owner(): void
    {
        $owner = $this->createUser();
        $this->grantPermissions($owner, ['files.view', 'files.download']);

        $folder = $this->createPrivateFolder($owner);
        $file = $this->createFile($folder, $owner, [
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        $this->assertFalse($owner->can('view', $file));
        $this->assertFalse($owner->can('download', $file));
    }

    public function test_owner_is_denied_without_global_view_permission(): void
    {
        $owner = $this->createUser();
        $folder = $this->createPrivateFolder($owner);
        $file = $this->createFile($folder, $owner);

        $this->assertFalse($owner->can('view', $file));
    }
}
