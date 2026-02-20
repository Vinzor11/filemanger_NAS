<?php

namespace App\Services;

use App\Models\File;
use App\Models\FilePermission;
use App\Models\Folder;
use App\Models\FolderPermission;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ExplorerService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{folders:\Illuminate\Support\Collection<int, Folder>, files:LengthAwarePaginator}
     */
    public function myFiles(User $user, array $filters): array
    {
        $folders = Folder::query()
            ->whereNull('parent_id')
            ->where('owner_user_id', $user->id)
            ->where('is_deleted', false)
            ->with([
                'owner',
                'permissions.user.employee',
            ])
            ->orderBy('name')
            ->get();
        $folders = $this->appendSharingMetadataToFolders($folders);

        $files = File::query()
            // Root view should only show root-level content; files belong to folders
            // and are displayed when navigating into a specific folder.
            ->whereRaw('1 = 0')
            ->with([
                'owner',
                'folder',
                'department',
                'permissions.user.employee',
            ])
            ->tap(fn (Builder $query) => $this->applyFileFilters($query, $filters))
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
        $files->setCollection(
            $this->appendSharingMetadataToFiles($files->getCollection()),
        );

        return compact('folders', 'files');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{folders:\Illuminate\Support\Collection<int, Folder>, files:LengthAwarePaginator}
     */
    public function departmentFiles(User $user, array $filters): array
    {
        $departmentId = $user->employee?->department_id;

        $departmentFolders = new Collection;
        if ($departmentId !== null) {
            $departmentFolders = Folder::query()
                ->whereNull('parent_id')
                ->where('department_id', $departmentId)
                ->where('is_deleted', false)
                ->with([
                    'owner',
                    'permissions.user.employee',
                ])
                ->orderBy('name')
                ->get();
        }
        $departmentFolderIds = $departmentFolders
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $departmentFolderSubtreeIds = $departmentFolderIds !== []
            ? $this->collectSubtreeFolderIdsForMany($departmentFolderIds)
            : [];

        $sharedFolderIds = FolderPermission::query()
            ->where('user_id', $user->id)
            ->where('can_view', true)
            ->pluck('folder_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $sharedFolders = new Collection;
        $sharedFolderSubtreeIds = [];

        if ($sharedFolderIds !== []) {
            $directlySharedFolders = Folder::query()
                ->whereIn('id', $sharedFolderIds)
                ->where('is_deleted', false)
                ->with([
                    'owner',
                    'permissions.user.employee',
                ])
                ->orderBy('name')
                ->get();

            $activeSharedFolderIds = $directlySharedFolders
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $sharedFolderLookup = array_fill_keys($activeSharedFolderIds, true);
            $departmentSubtreeLookup = array_fill_keys($departmentFolderSubtreeIds, true);
            /** @var array<int, int|null> $parentLookup */
            $parentLookup = $directlySharedFolders
                ->mapWithKeys(fn (Folder $folder): array => [$folder->id => $folder->parent_id])
                ->all();

            $sharedFolders = $directlySharedFolders
                ->filter(function (Folder $folder) use (&$parentLookup, $departmentSubtreeLookup, $sharedFolderLookup): bool {
                    if (isset($departmentSubtreeLookup[$folder->id])) {
                        return false;
                    }

                    if ($this->hasSharedAncestor($folder->parent_id, $departmentSubtreeLookup, $parentLookup)) {
                        return false;
                    }

                    return ! $this->hasSharedAncestor(
                        $folder->parent_id,
                        $sharedFolderLookup,
                        $parentLookup,
                    );
                })
                ->values();

            $visibleSharedRootFolderIds = $sharedFolders
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $sharedFolderSubtreeIds = $visibleSharedRootFolderIds !== []
                ? $this->collectSubtreeFolderIdsForMany($visibleSharedRootFolderIds)
                : [];
        }

        $folders = $departmentFolders
            ->concat($sharedFolders)
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->map(function (Folder $folder) use ($departmentId, $user): Folder {
                $directPermission = $folder->permissions
                    ->first(fn (FolderPermission $permission): bool => $permission->user_id === $user->id);
                $isDepartmentVisible = $departmentId !== null
                    && $folder->department_id === $departmentId
                    && $folder->visibility === 'department';

                $folder->setAttribute('access', [
                    'can_view' => (bool) ($directPermission?->can_view ?? $isDepartmentVisible),
                    'can_upload' => $isDepartmentVisible
                        ? $user->can('files.upload')
                        : (bool) (
                            ($directPermission?->can_upload ?? false) ||
                            ($directPermission?->can_edit ?? false)
                        ),
                    'can_edit' => $isDepartmentVisible
                        ? $user->can('folders.update')
                        : (bool) ($directPermission?->can_edit ?? false),
                    'can_delete' => $isDepartmentVisible && $user->can('folders.delete'),
                ]);

                return $folder;
            });
        $folders = $this->appendSharingMetadataToFolders($folders);

        $folderSubtreeIdsToHideFromRootFiles = array_values(array_unique(array_merge(
            $departmentFolderSubtreeIds,
            $sharedFolderSubtreeIds,
        )));

        $files = File::query()
            ->where('is_deleted', false)
            ->where(function (Builder $scopeQuery) use ($departmentId, $user): void {
                $scopeQuery->whereIn('id', function ($permissionQuery) use ($user): void {
                    $permissionQuery->select('file_id')
                        ->from((new FilePermission)->getTable())
                        ->where('user_id', $user->id)
                        ->where('can_view', true);
                });

                if ($departmentId !== null) {
                    $scopeQuery->orWhere(function (Builder $departmentQuery) use ($departmentId): void {
                        $departmentQuery->where('department_id', $departmentId)
                            ->where('visibility', 'department');
                    });
                }
            })
            ->when($folderSubtreeIdsToHideFromRootFiles !== [], function (Builder $query) use ($folderSubtreeIdsToHideFromRootFiles): void {
                $query->whereNotIn('folder_id', $folderSubtreeIdsToHideFromRootFiles);
            })
            ->with([
                'owner',
                'folder:id,public_id,name,path,visibility',
                'department',
                'permissions' => fn ($query) => $query->where('user_id', $user->id),
            ])
            ->tap(fn (Builder $query) => $this->applyFileFilters($query, $filters))
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        $files->setCollection(
            $this->appendSharingMetadataToFiles(
                $files->getCollection()->map(function (File $file) use ($departmentId, $user): File {
                    $directPermission = $file->permissions->first();
                    $isDepartmentVisible = $departmentId !== null
                        && $file->department_id === $departmentId
                        && $file->visibility === 'department';

                    $file->setAttribute('access', [
                        'can_view' => (bool) ($directPermission?->can_view ?? $isDepartmentVisible),
                        'can_download' => $isDepartmentVisible ? $user->can('files.download') : false,
                        'can_edit' => $isDepartmentVisible
                            ? $user->can('files.update')
                            : (bool) ($directPermission?->can_edit ?? false),
                        'can_delete' => $isDepartmentVisible && $user->can('files.delete'),
                    ]);

                    return $file;
                }),
            ),
        );

        return compact('folders', 'files');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{folders:Collection<int, Folder>, files:LengthAwarePaginator}
     */
    public function sharedWithMe(User $user, array $filters): array
    {
        $sharedFolderIds = FolderPermission::query()
            ->where('user_id', $user->id)
            ->where('can_view', true)
            ->pluck('folder_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $folders = new Collection;
        $sharedFolderSubtreeIds = [];

        if ($sharedFolderIds !== []) {
            $directlySharedFolders = Folder::query()
                ->whereIn('id', $sharedFolderIds)
                ->where('is_deleted', false)
                ->with([
                    'owner',
                    'permissions' => fn ($query) => $query->where('user_id', $user->id),
                ])
                ->orderBy('name')
                ->get();

            $activeSharedFolderIds = $directlySharedFolders
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $sharedFolderLookup = array_fill_keys($activeSharedFolderIds, true);
            /** @var array<int, int|null> $parentLookup */
            $parentLookup = $directlySharedFolders
                ->mapWithKeys(fn (Folder $folder): array => [$folder->id => $folder->parent_id])
                ->all();

            $folders = $directlySharedFolders
                ->filter(function (Folder $folder) use (&$parentLookup, $sharedFolderLookup): bool {
                    return ! $this->hasSharedAncestor(
                        $folder->parent_id,
                        $sharedFolderLookup,
                        $parentLookup,
                    );
                })
                ->values()
                ->map(function (Folder $folder): Folder {
                    $directPermission = $folder->permissions->first();

                    $folder->setAttribute('access', [
                        'can_view' => (bool) ($directPermission?->can_view ?? false),
                        'can_upload' => (bool) ($directPermission?->can_upload ?? false),
                        'can_edit' => (bool) ($directPermission?->can_edit ?? false),
                        'can_delete' => false,
                    ]);

                    return $folder;
                });

            $sharedFolderSubtreeIds = $this->collectSubtreeFolderIdsForMany($activeSharedFolderIds);
        }

        $files = File::query()
            ->where('is_deleted', false)
            ->whereIn('id', function ($query) use ($user): void {
                $query->select('file_id')
                    ->from((new FilePermission)->getTable())
                    ->where('user_id', $user->id)
                    ->where('can_view', true);
            })
            ->when($sharedFolderSubtreeIds !== [], function (Builder $query) use ($sharedFolderSubtreeIds): void {
                $query->whereNotIn('folder_id', $sharedFolderSubtreeIds);
            })
            ->with([
                'owner',
                'department',
                'permissions' => fn ($query) => $query->where('user_id', $user->id),
                'folder:id,public_id,name,path,visibility',
            ])
            ->tap(fn (Builder $query) => $this->applyFileFilters($query, $filters))
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        $files->setCollection(
            $files->getCollection()->map(function (File $file): File {
                $directPermission = $file->permissions->first();

                $file->setAttribute('access', [
                    'can_view' => (bool) ($directPermission?->can_view ?? false),
                    'can_download' => false,
                    'can_edit' => (bool) ($directPermission?->can_edit ?? false),
                    'can_delete' => false,
                ]);

                return $file;
            }),
        );

        return compact('folders', 'files');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{children:\Illuminate\Support\Collection<int, Folder>, files:LengthAwarePaginator}
     */
    public function folderContents(User $user, Folder $folder, array $filters): array
    {
        $folderAccess = $this->folderAccessForUser($user, $folder);

        $children = Folder::query()
            ->where('parent_id', $folder->id)
            ->where('is_deleted', false)
            ->with([
                'owner',
                'permissions.user.employee',
            ])
            ->orderBy('name')
            ->get();
        $children = $this->appendSharingMetadataToFolders($children)->map(
            function (Folder $child) use ($folderAccess, $user): Folder {
                $isOwner = $child->owner_user_id === $user->id;
                $isSameDepartment =
                    $child->department_id !== null &&
                    $user->employee?->department_id === $child->department_id;
                $directPermission = $child->permissions
                    ->first(fn (FolderPermission $permission): bool => $permission->user_id === $user->id);

                $child->setAttribute('access', [
                    'can_view' => true,
                    'can_upload' =>
                        $isOwner ||
                        ($isSameDepartment && $user->can('files.upload')) ||
                        (bool) ($directPermission?->can_upload ?? false) ||
                        (bool) ($directPermission?->can_edit ?? false) ||
                        (bool) $folderAccess['can_upload'] ||
                        (bool) $folderAccess['can_edit'],
                    'can_edit' =>
                        $isOwner ||
                        ($isSameDepartment && $user->can('folders.update')) ||
                        (bool) ($directPermission?->can_edit ?? false) ||
                        (bool) $folderAccess['can_edit'],
                    'can_delete' =>
                        $isOwner ||
                        ($isSameDepartment && $user->can('folders.delete')),
                ]);

                return $child;
            },
        );

        $files = File::query()
            ->where('folder_id', $folder->id)
            ->where('is_deleted', false)
            ->with([
                'owner',
                'folder',
                'department',
                'permissions.user.employee',
            ])
            ->tap(fn (Builder $query) => $this->applyFileFilters($query, $filters))
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
        $files->setCollection($this->appendSharingMetadataToFiles($files->getCollection())->map(
            function (File $file) use ($folderAccess, $user): File {
                $isOwner = $file->owner_user_id === $user->id;
                $isSameDepartment =
                    $file->department_id !== null &&
                    $user->employee?->department_id === $file->department_id;
                $directPermission = $file->permissions
                    ->first(fn (FilePermission $permission): bool => $permission->user_id === $user->id);

                $file->setAttribute('access', [
                    'can_view' => true,
                    'can_download' =>
                        $isOwner ||
                        ($isSameDepartment &&
                            $user->can('files.download') &&
                            $file->visibility !== 'private') ||
                        (bool) ($directPermission?->can_download ?? false) ||
                        (bool) $folderAccess['can_view'],
                    'can_edit' =>
                        $isOwner ||
                        ($isSameDepartment && $user->can('files.update')) ||
                        (bool) ($directPermission?->can_edit ?? false) ||
                        (bool) $folderAccess['can_edit'],
                    'can_delete' =>
                        $isOwner ||
                        ($isSameDepartment && $user->can('files.delete')),
                ]);

                return $file;
            },
        ));

        return compact('children', 'files');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{folders:LengthAwarePaginator, files:LengthAwarePaginator}
     */
    public function trash(User $user, array $filters): array
    {
        $folders = Folder::query()
            ->where('is_deleted', true)
            ->where(function (Builder $query): void {
                $query->whereNull('parent_id')
                    ->orWhereHas('parent', fn (Builder $parentQuery) => $parentQuery->where('is_deleted', false));
            })
            ->tap(fn (Builder $query) => $this->applyTrashScope($query, $user))
            ->with('owner')
            ->orderByDesc('deleted_at')
            ->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'folders_page')
            ->withQueryString();

        $folders->setCollection(
            $folders->getCollection()->map(function (Folder $folder): Folder {
                $folder->setAttribute(
                    'trashed_files_count',
                    $this->countTrashedFilesInSubtree($folder),
                );

                return $folder;
            }),
        );

        $files = File::query()
            ->where('is_deleted', true)
            ->whereHas('folder', fn (Builder $folderQuery) => $folderQuery->where('is_deleted', false))
            ->tap(fn (Builder $query) => $this->applyTrashScope($query, $user))
            ->with(['owner', 'folder:id,public_id,name,path,visibility,is_deleted'])
            ->orderByDesc('deleted_at')
            ->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'files_page')
            ->withQueryString();

        return compact('folders', 'files');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *   folder:Folder,
     *   breadcrumbTrail:list<array{public_id:string,name:string}>,
     *   children:Collection<int, Folder>,
     *   files:LengthAwarePaginator
     * }
     */
    public function trashFolderContents(User $user, Folder $folder, array $filters): array
    {
        $record = Folder::query()
            ->where('id', $folder->id)
            ->where('is_deleted', true)
            ->tap(fn (Builder $query) => $this->applyTrashScope($query, $user))
            ->with('owner')
            ->firstOrFail();

        $children = Folder::query()
            ->where('parent_id', $record->id)
            ->where('is_deleted', true)
            ->tap(fn (Builder $query) => $this->applyTrashScope($query, $user))
            ->with('owner')
            ->orderBy('name')
            ->get()
            ->map(function (Folder $child): Folder {
                $child->setAttribute(
                    'trashed_files_count',
                    $this->countTrashedFilesInSubtree($child),
                );

                return $child;
            });

        $files = File::query()
            ->where('folder_id', $record->id)
            ->where('is_deleted', true)
            ->tap(fn (Builder $query) => $this->applyTrashScope($query, $user))
            ->with(['owner', 'folder:id,public_id,name,path,visibility,is_deleted'])
            ->tap(fn (Builder $query) => $this->applyFileFilters($query, $filters))
            ->orderByDesc('deleted_at')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return [
            'folder' => $record,
            'breadcrumbTrail' => $this->trashBreadcrumbTrail($record),
            'children' => $children,
            'files' => $files,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFileFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['q'])) {
            $needle = trim((string) $filters['q']);
            $query->where(function (Builder $inner) use ($needle): void {
                $inner->where('original_name', 'like', "%{$needle}%")
                    ->orWhere('mime_type', 'like', "%{$needle}%");
            });
        }

        if (! empty($filters['type'])) {
            $query->where('extension', strtolower((string) $filters['type']));
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', (string) $filters['date_to']);
        }

        if (! empty($filters['visibility'])) {
            $query->where('visibility', (string) $filters['visibility']);
        }
    }

    private function applyTrashScope(Builder $query, User $user): void
    {
        $query->where(function (Builder $scopeQuery) use ($user): void {
            $scopeQuery->where('owner_user_id', $user->id)
                ->orWhere('department_id', $user->employee?->department_id);
        });
    }

    /**
     * @param  array<int, true>  $sharedFolderLookup
     * @param  array<int, int|null>  $parentLookup
     */
    private function hasSharedAncestor(
        ?int $startParentId,
        array $sharedFolderLookup,
        array &$parentLookup,
    ): bool {
        $currentParentId = $startParentId;
        $visited = [];

        while ($currentParentId !== null) {
            if (isset($sharedFolderLookup[$currentParentId])) {
                return true;
            }

            if (isset($visited[$currentParentId])) {
                break;
            }
            $visited[$currentParentId] = true;

            if (! array_key_exists($currentParentId, $parentLookup)) {
                $parentLookup[$currentParentId] = Folder::query()
                    ->where('id', $currentParentId)
                    ->value('parent_id');
            }

            $currentParentId = $parentLookup[$currentParentId];
        }

        return false;
    }

    /**
     * @param  list<int>  $rootFolderIds
     * @return list<int>
     */
    private function collectSubtreeFolderIdsForMany(array $rootFolderIds): array
    {
        $allIds = [];

        foreach (array_values(array_unique($rootFolderIds)) as $rootFolderId) {
            $allIds = array_merge($allIds, $this->collectSubtreeFolderIds($rootFolderId));
        }

        return array_values(array_unique($allIds));
    }

    /**
     * @return list<int>
     */
    private function collectSubtreeFolderIds(int $rootFolderId): array
    {
        $ids = [$rootFolderId];
        $cursor = [$rootFolderId];

        while ($cursor !== []) {
            $next = Folder::query()
                ->whereIn('parent_id', $cursor)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($next === []) {
                break;
            }

            $ids = array_merge($ids, $next);
            $cursor = $next;
        }

        return array_values(array_unique($ids));
    }

    private function countTrashedFilesInSubtree(Folder $folder): int
    {
        $subtreeIds = $this->collectSubtreeFolderIds($folder->id);

        return File::query()
            ->whereIn('folder_id', $subtreeIds)
            ->where('is_deleted', true)
            ->count();
    }

    /**
     * @return list<array{public_id:string,name:string}>
     */
    private function trashBreadcrumbTrail(Folder $folder): array
    {
        $trail = [];
        $current = Folder::query()
            ->select(['id', 'parent_id', 'public_id', 'name', 'is_deleted'])
            ->find($folder->id);

        while ($current) {
            if (! $current->is_deleted && $current->id !== $folder->id) {
                break;
            }

            $trail[] = [
                'public_id' => $current->public_id,
                'name' => $current->name,
            ];

            if ($current->parent_id === null) {
                break;
            }

            $current = Folder::query()
                ->select(['id', 'parent_id', 'public_id', 'name', 'is_deleted'])
                ->find($current->parent_id);
        }

        return array_values(array_reverse($trail));
    }

    /**
     * @return array{can_view:bool,can_upload:bool,can_edit:bool,can_delete:bool}
     */
    private function folderAccessForUser(User $user, Folder $folder): array
    {
        $ancestorIds = $this->ancestorFolderIds($folder->id);

        return [
            'can_view' => FolderPermission::query()
                ->whereIn('folder_id', $ancestorIds)
                ->where('user_id', $user->id)
                ->where('can_view', true)
                ->exists(),
            'can_upload' => FolderPermission::query()
                ->whereIn('folder_id', $ancestorIds)
                ->where('user_id', $user->id)
                ->where('can_upload', true)
                ->exists(),
            'can_edit' => FolderPermission::query()
                ->whereIn('folder_id', $ancestorIds)
                ->where('user_id', $user->id)
                ->where('can_edit', true)
                ->exists(),
            'can_delete' => FolderPermission::query()
                ->whereIn('folder_id', $ancestorIds)
                ->where('user_id', $user->id)
                ->where('can_delete', true)
                ->exists(),
        ];
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

    /**
     * @param  Collection<int, Folder>  $folders
     * @return Collection<int, Folder>
     */
    private function appendSharingMetadataToFolders(Collection $folders): Collection
    {
        return $folders->map(function (Folder $folder): Folder {
            $sharedWith = $folder->permissions
                ->filter(fn (FolderPermission $permission): bool => (bool) $permission->can_view && $permission->user !== null)
                ->map(fn (FolderPermission $permission): array => $this->formatSharingUser($permission->user))
                ->unique(fn (array $entry): string => (string) ($entry['public_id'] ?? ''))
                ->values()
                ->all();

            $folder->setAttribute('sharing', [
                'is_shared' => $sharedWith !== [],
                'shared_with' => $sharedWith,
            ]);

            return $folder;
        });
    }

    /**
     * @param  Collection<int, File>  $files
     * @return Collection<int, File>
     */
    private function appendSharingMetadataToFiles(Collection $files): Collection
    {
        return $files->map(function (File $file): File {
            $sharedWith = $file->permissions
                ->filter(fn (FilePermission $permission): bool => (bool) $permission->can_view && $permission->user !== null)
                ->map(fn (FilePermission $permission): array => $this->formatSharingUser($permission->user))
                ->unique(fn (array $entry): string => (string) ($entry['public_id'] ?? ''))
                ->values()
                ->all();

            if ($file->visibility === 'department' && $file->department !== null) {
                $sharedWith[] = [
                    'type' => 'department',
                    'public_id' => null,
                    'name' => $file->department->name,
                    'email' => null,
                ];
            }

            $file->setAttribute('sharing', [
                'is_shared' => $sharedWith !== [],
                'shared_with' => $sharedWith,
            ]);

            return $file;
        });
    }

    /**
     * @return array{
     *   type:'user',
     *   public_id:string,
     *   name:string,
     *   email:string|null
     * }
     */
    private function formatSharingUser(User $user): array
    {
        $fullName = trim(($user->employee?->first_name ?? '').' '.($user->employee?->last_name ?? ''));
        $fallbackName = $user->email ?? 'Unknown user';

        return [
            'type' => 'user',
            'public_id' => $user->public_id,
            'name' => $fullName !== '' ? $fullName : $fallbackName,
            'email' => $user->email,
        ];
    }
}
