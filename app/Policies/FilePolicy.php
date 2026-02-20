<?php

namespace App\Policies;

use App\Models\File;
use App\Models\Folder;
use App\Models\FolderPermission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class FilePolicy
{
    use HandlesAuthorization;

    public function view(User $user, File $file): bool
    {
        if ($file->is_deleted) {
            return false;
        }

        if ($this->hasDirectFilePermission($user, $file, 'can_view')
            || $this->hasFolderPermission($user, $file, 'can_view')
        ) {
            return true;
        }

        if (! $user->can('files.view')) {
            return false;
        }

        if ($file->owner_user_id === $user->id) {
            return true;
        }

        if ($this->isSameDepartment($user, $file->department_id)
            && in_array($file->visibility, ['department', 'shared'], true)
        ) {
            if ($this->hasDepartmentPermissionOverrides($file)) {
                return $this->hasDirectFilePermission($user, $file, 'can_view')
                    || $this->hasFolderPermission($user, $file, 'can_view');
            }

            return true;
        }

        return false;
    }

    public function download(User $user, File $file): bool
    {
        if ($file->is_deleted) {
            return false;
        }

        if ($this->hasDirectFilePermission($user, $file, 'can_download')
            || $this->hasFolderPermission($user, $file, 'can_view')
        ) {
            return true;
        }

        if (! $user->can('files.download') || ! $this->view($user, $file)) {
            return false;
        }

        if ($file->owner_user_id === $user->id) {
            return true;
        }

        if ($this->isSameDepartment($user, $file->department_id) && $file->visibility !== 'private') {
            if ($this->hasDepartmentPermissionOverrides($file)) {
                return $this->hasDirectFilePermission($user, $file, 'can_download')
                    || $this->hasFolderPermission($user, $file, 'can_view');
            }

            return true;
        }

        return false;
    }

    public function update(User $user, File $file): bool
    {
        if ($this->hasDirectFilePermission($user, $file, 'can_edit')
            || $this->hasFolderPermission($user, $file, 'can_edit')
        ) {
            return true;
        }

        if (! $user->can('files.update')) {
            return false;
        }

        if ($file->owner_user_id === $user->id) {
            return true;
        }

        if ($this->isSameDepartment($user, $file->department_id)) {
            if ($this->hasDepartmentPermissionOverrides($file)) {
                return $this->hasDirectFilePermission($user, $file, 'can_edit')
                    || $this->hasFolderPermission($user, $file, 'can_edit');
            }

            return true;
        }

        return false;
    }

    public function delete(User $user, File $file): bool
    {
        if ($this->hasDirectFilePermission($user, $file, 'can_delete')
            || $this->hasFolderPermission($user, $file, 'can_delete')
        ) {
            return true;
        }

        if (! $user->can('files.delete')) {
            return false;
        }

        if ($file->owner_user_id === $user->id) {
            return true;
        }

        if ($this->isSameDepartment($user, $file->department_id)) {
            if ($this->hasDepartmentPermissionOverrides($file)) {
                return false;
            }

            return true;
        }

        return false;
    }

    public function restore(User $user, File $file): bool
    {
        return $user->can('files.restore') && $this->delete($user, $file);
    }

    public function share(User $user, File $file): bool
    {
        if (! $user->can('share.manage') || ! $this->view($user, $file)) {
            return false;
        }

        if ($file->owner_user_id === $user->id || $this->isSameDepartment($user, $file->department_id)) {
            return true;
        }

        return $this->hasDirectFilePermission($user, $file, 'can_edit')
            || $this->hasFolderPermission($user, $file, 'can_edit');
    }

    private function isSameDepartment(User $user, ?int $departmentId): bool
    {
        if ($departmentId === null || $user->employee === null) {
            return false;
        }

        return $user->employee->department_id === $departmentId;
    }

    private function hasDirectFilePermission(User $user, File $file, string $column): bool
    {
        return $file->permissions()
            ->where('user_id', $user->id)
            ->where($column, true)
            ->exists();
    }

    private function hasFolderPermission(User $user, File $file, string $column): bool
    {
        $folder = $file->folder;
        if (! $folder) {
            return false;
        }

        return FolderPermission::query()
            ->whereIn('folder_id', $this->ancestorFolderIds($folder->id))
            ->where('user_id', $user->id)
            ->where($column, true)
            ->exists();
    }

    private function hasDepartmentPermissionOverrides(File $file): bool
    {
        return $file->permissions()->exists();
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
