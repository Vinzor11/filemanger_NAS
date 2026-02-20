<?php

namespace App\Policies;

use App\Models\Folder;
use App\Models\FolderPermission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class FolderPolicy
{
    use HandlesAuthorization;

    public function view(User $user, Folder $folder): bool
    {
        if ($folder->is_deleted) {
            return false;
        }

        if ($this->hasFolderPermission($user, $folder, 'can_view')) {
            return true;
        }

        if (! $user->can('files.view')) {
            return false;
        }

        if ($folder->owner_user_id === $user->id) {
            return true;
        }

        if ($this->isSameDepartment($user, $folder->department_id)
            && in_array($folder->visibility, ['department', 'shared'], true)
        ) {
            if ($this->hasDepartmentPermissionOverrides($folder)) {
                return $this->hasFolderPermission($user, $folder, 'can_view');
            }

            return true;
        }

        return false;
    }

    public function create(User $user, ?Folder $parent = null): bool
    {
        if (! $user->can('folders.create')) {
            return false;
        }

        if ($parent === null) {
            return true;
        }

        return $this->update($user, $parent);
    }

    public function update(User $user, Folder $folder): bool
    {
        if ($this->hasFolderPermission($user, $folder, 'can_edit')) {
            return true;
        }

        if (! $user->can('folders.update')) {
            return false;
        }

        if ($folder->owner_user_id === $user->id) {
            return true;
        }

        if ($this->isSameDepartment($user, $folder->department_id)) {
            if ($this->hasDepartmentPermissionOverrides($folder)) {
                return $this->hasFolderPermission($user, $folder, 'can_edit');
            }

            return true;
        }

        return false;
    }

    public function upload(User $user, Folder $folder): bool
    {
        if ($folder->is_deleted) {
            return false;
        }

        if (
            $this->hasFolderPermission($user, $folder, 'can_upload') ||
            $this->hasFolderPermission($user, $folder, 'can_edit')
        ) {
            return true;
        }

        if (! $user->can('files.upload')) {
            return false;
        }

        if ($folder->owner_user_id === $user->id) {
            return true;
        }

        if ($this->isSameDepartment($user, $folder->department_id)) {
            if ($this->hasDepartmentPermissionOverrides($folder)) {
                return $this->hasFolderPermission($user, $folder, 'can_upload');
            }

            return true;
        }

        return false;
    }

    public function delete(User $user, Folder $folder): bool
    {
        if (! $user->can('folders.delete')) {
            return false;
        }

        if ($folder->owner_user_id === $user->id) {
            return true;
        }

        if ($this->isSameDepartment($user, $folder->department_id)) {
            if ($this->hasDepartmentPermissionOverrides($folder)) {
                return false;
            }

            return true;
        }

        return false;
    }

    public function restore(User $user, Folder $folder): bool
    {
        return $user->can('folders.restore') && $this->delete($user, $folder);
    }

    public function share(User $user, Folder $folder): bool
    {
        if (! $user->can('share.manage') || ! $this->view($user, $folder)) {
            return false;
        }

        if ($folder->owner_user_id === $user->id || $this->isSameDepartment($user, $folder->department_id)) {
            return true;
        }

        return $this->hasFolderPermission($user, $folder, 'can_edit');
    }

    private function isSameDepartment(User $user, ?int $departmentId): bool
    {
        if ($departmentId === null || $user->employee === null) {
            return false;
        }

        return $user->employee->department_id === $departmentId;
    }

    private function hasFolderPermission(User $user, Folder $folder, string $column): bool
    {
        return FolderPermission::query()
            ->whereIn('folder_id', $this->ancestorFolderIds($folder->id))
            ->where('user_id', $user->id)
            ->where($column, true)
            ->exists();
    }

    private function hasDepartmentPermissionOverrides(Folder $folder): bool
    {
        return $folder->permissions()->exists();
    }

    /**
     * @return list<int>
     */
    private function ancestorFolderIds(int $folderId): array
    {
        $ids = [];
        $currentFolderId = $folderId;
        $visited = [];

        while (! isset($visited[$currentFolderId])) {
            $ids[] = $currentFolderId;
            $visited[$currentFolderId] = true;

            $parentId = Folder::query()
                ->where('id', $currentFolderId)
                ->value('parent_id');

            if ($parentId === null) {
                break;
            }

            $currentFolderId = (int) $parentId;
        }

        return $ids;
    }
}
