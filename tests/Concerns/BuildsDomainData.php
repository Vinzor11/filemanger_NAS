<?php

namespace Tests\Concerns;

use App\Models\Department;
use App\Models\Employee;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

trait BuildsDomainData
{
    protected function fileStorageDisk(): string
    {
        return (string) config('filesystems.file_storage_disk', config('filesystems.default', 'local'));
    }

    protected function createDepartment(array $attributes = []): Department
    {
        return Department::factory()->create($attributes);
    }

    protected function createEmployee(?Department $department = null, array $attributes = []): Employee
    {
        $department ??= $this->createDepartment();

        return Employee::factory()->create(array_merge([
            'department_id' => $department->id,
        ], $attributes));
    }

    protected function createUser(
        ?Department $department = null,
        array $userAttributes = [],
        array $employeeAttributes = [],
    ): User {
        $employee = $this->createEmployee($department, $employeeAttributes);

        return User::factory()->create(array_merge([
            'employee_id' => $employee->id,
            'status' => 'active',
            'approved_at' => now(),
        ], $userAttributes));
    }

    protected function createPrivateFolder(User $owner, array $attributes = []): Folder
    {
        return Folder::query()->create(array_merge([
            'parent_id' => null,
            'name' => 'Folder '.Str::random(6),
            'owner_user_id' => $owner->id,
            'department_id' => null,
            'path' => null,
            'visibility' => 'private',
            'is_deleted' => false,
            'deleted_at' => null,
        ], $attributes));
    }

    protected function createDepartmentFolder(Department $department, array $attributes = []): Folder
    {
        return Folder::query()->create(array_merge([
            'parent_id' => null,
            'name' => 'Department Folder '.Str::random(6),
            'owner_user_id' => null,
            'department_id' => $department->id,
            'path' => null,
            'visibility' => 'department',
            'is_deleted' => false,
            'deleted_at' => null,
        ], $attributes));
    }

    protected function createFile(Folder $folder, User $owner, array $attributes = []): File
    {
        $storedName = $attributes['stored_name'] ?? ((string) Str::uuid().'.txt');

        return File::query()->create(array_merge([
            'folder_id' => $folder->id,
            'owner_user_id' => $owner->id,
            'department_id' => $folder->department_id,
            'original_name' => $attributes['original_name'] ?? ('file-'.Str::random(5).'.txt'),
            'stored_name' => $storedName,
            'extension' => pathinfo($storedName, PATHINFO_EXTENSION) ?: 'txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 100,
            'checksum_sha256' => null,
            'storage_disk' => $this->fileStorageDisk(),
            'storage_path' => $attributes['storage_path'] ?? 'private/'.$owner->public_id.'/'.$folder->public_id.'/'.now()->format('Y/m').'/'.$storedName,
            'visibility' => $folder->visibility,
            'is_deleted' => false,
            'deleted_at' => null,
        ], $attributes));
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function grantPermissions(User $user, array $permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);
    }
}
