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
        $departmentId = $user->employee?->department_id;
        $searchNeedle = $this->searchNeedle($filters);

        $ownedRootFolders = Folder::query()
            ->whereNull('parent_id')
            ->where('owner_user_id', $user->id)
            ->where('is_deleted', false)
            ->with([
                'owner',
                'permissions.user.employee',
            ])
            ->orderBy('name')
            ->get();
        $ownedRootFolderIds = $ownedRootFolders
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $ownedFolderSubtreeIds = $ownedRootFolderIds !== []
            ? $this->collectSubtreeFolderIdsForMany($ownedRootFolderIds)
            : [];

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
            $ownedSubtreeLookup = array_fill_keys($ownedFolderSubtreeIds, true);
            $departmentSubtreeLookup = array_fill_keys($departmentFolderSubtreeIds, true);
            /** @var array<int, int|null> $parentLookup */
            $parentLookup = $directlySharedFolders
                ->mapWithKeys(fn (Folder $folder): array => [$folder->id => $folder->parent_id])
                ->all();

            $sharedFolders = $directlySharedFolders
                ->filter(function (Folder $folder) use (&$parentLookup, $departmentSubtreeLookup, $ownedSubtreeLookup, $sharedFolderLookup): bool {
                    if (isset($ownedSubtreeLookup[$folder->id]) || isset($departmentSubtreeLookup[$folder->id])) {
                        return false;
                    }

                    if (
                        $this->hasSharedAncestor($folder->parent_id, $ownedSubtreeLookup, $parentLookup) ||
                        $this->hasSharedAncestor($folder->parent_id, $departmentSubtreeLookup, $parentLookup)
                    ) {
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

        $searchableFolderIds = array_values(array_unique(array_merge(
            $ownedFolderSubtreeIds,
            $departmentFolderSubtreeIds,
            $sharedFolderSubtreeIds,
        )));

        if ($searchNeedle === null) {
            $folders = $ownedRootFolders
                ->concat($departmentFolders)
                ->concat($sharedFolders)
                ->unique('id')
                ->sortBy('name')
                ->values();
        } elseif ($searchableFolderIds === []) {
            $folders = new Collection;
        } else {
            $folders = Folder::query()
                ->where('is_deleted', false)
                ->whereIn('id', $searchableFolderIds)
                ->tap(fn (Builder $query) => $this->applyFolderFilters($query, $filters))
                ->with([
                    'owner',
                    'permissions.user.employee',
                ])
                ->orderBy('path')
                ->orderBy('name')
                ->get();
        }
        $folders = $folders->map(function (Folder $folder) use ($departmentId, $user): Folder {
            $isOwner = $folder->owner_user_id === $user->id;
            $isDepartmentVisible = $departmentId !== null
                && $folder->department_id === $departmentId
                && $folder->visibility === 'department';
            $directPermission = $folder->permissions
                ->first(fn (FolderPermission $permission): bool => $permission->user_id === $user->id);
            $hasDirectPermission = $directPermission !== null;
            $folderAccess = $this->folderAccessForUser($user, $folder);

            $folder->setAttribute('access', [
                'can_view' => $isOwner
                    ? true
                    : (
                        $hasDirectPermission
                            ? (bool) $directPermission->can_view
                            : ($isDepartmentVisible || (bool) $folderAccess['can_view'])
                    ),
                'can_upload' => $isOwner
                    ? true
                    : (
                        $hasDirectPermission
                            ? (bool) ($directPermission->can_upload || $directPermission->can_edit)
                            : (
                                $isDepartmentVisible
                                    ? $user->can('files.upload')
                                    : (
                                        (bool) $folderAccess['can_upload'] ||
                                        (bool) $folderAccess['can_edit']
                                    )
                            )
                    ),
                'can_edit' => $isOwner
                    ? true
                    : (
                        $hasDirectPermission
                            ? (bool) $directPermission->can_edit
                            : (
                                $isDepartmentVisible
                                    ? $user->can('folders.update')
                                    : (bool) $folderAccess['can_edit']
                            )
                    ),
                'can_delete' => $isOwner
                    ? true
                    : (
                        $hasDirectPermission
                            ? (bool) $directPermission->can_delete
                            : (
                                $isDepartmentVisible
                                    ? $user->can('folders.delete')
                                    : (bool) $folderAccess['can_delete']
                            )
                    ),
            ]);

            return $folder;
        });
        $folders = $this->appendSharingMetadataToFolders($folders);
        $folders = $this->appendSourceMetadataToFolders($folders, $user, $departmentId);

        $folderSubtreeIdsToHideFromRootFiles = array_values(array_unique(array_merge(
            $ownedFolderSubtreeIds,
            $departmentFolderSubtreeIds,
            $sharedFolderSubtreeIds,
        )));

        $files = File::query()
            ->where('is_deleted', false)
            ->where(function (Builder $scopeQuery) use ($departmentId, $searchNeedle, $searchableFolderIds, $user): void {
                $scopeQuery->where('owner_user_id', $user->id);
                $scopeQuery->orWhereIn('id', function ($permissionQuery) use ($user): void {
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

                if ($searchNeedle !== null && $searchableFolderIds !== []) {
                    $scopeQuery->orWhereIn('folder_id', $searchableFolderIds);
                }
            })
            ->when($searchNeedle === null && $folderSubtreeIdsToHideFromRootFiles !== [], function (Builder $query) use ($folderSubtreeIdsToHideFromRootFiles): void {
                $query->whereNotIn('folder_id', $folderSubtreeIdsToHideFromRootFiles);
            })
            ->with([
                'owner',
                'folder:id,public_id,name,path,visibility,parent_id',
                'department',
                'permissions.user.employee',
            ])
            ->tap(fn (Builder $query) => $this->applyFileFilters($query, $filters))
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
        $files->setCollection(
            $this->appendSourceMetadataToFiles(
                $this->appendSharingMetadataToFiles(
                    $files->getCollection()->map(function (File $file) use ($departmentId, $user): File {
                        $isOwner = $file->owner_user_id === $user->id;
                        $directPermission = $file->permissions
                            ->first(fn (FilePermission $permission): bool => $permission->user_id === $user->id);
                        $isDepartmentVisible = $departmentId !== null
                            && $file->department_id === $departmentId
                            && $file->visibility === 'department';
                        $hasDirectPermission = $directPermission !== null;
                        $folderId = $file->folder?->id;
                        $folderAccess = is_int($folderId)
                            ? $this->folderAccessByFolderId($user, $folderId)
                            : [
                                'can_view' => false,
                                'can_upload' => false,
                                'can_edit' => false,
                                'can_delete' => false,
                            ];

                        $file->setAttribute('access', [
                            'can_view' => $isOwner
                                ? true
                                : (
                                    $hasDirectPermission
                                        ? (bool) $directPermission->can_view
                                        : ($isDepartmentVisible || (bool) $folderAccess['can_view'])
                                ),
                            'can_download' => $isOwner
                                ? true
                                : (
                                    $hasDirectPermission
                                        ? (bool) $directPermission->can_download
                                        : (
                                            $isDepartmentVisible
                                                ? $user->can('files.download')
                                                : (bool) $folderAccess['can_view']
                                        )
                                ),
                            'can_edit' => $isOwner
                                ? true
                                : (
                                    $hasDirectPermission
                                        ? (bool) $directPermission->can_edit
                                        : (
                                            $isDepartmentVisible
                                                ? $user->can('files.update')
                                                : (bool) $folderAccess['can_edit']
                                        )
                                ),
                            'can_delete' => $isOwner
                                ? true
                                : (
                                    $hasDirectPermission
                                        ? (bool) $directPermission->can_delete
                                        : (
                                            $isDepartmentVisible
                                                ? $user->can('files.delete')
                                                : (bool) $folderAccess['can_delete']
                                        )
                                ),
                        ]);

                        return $file;
                    }),
                ),
                $user,
                $departmentId,
            ),
        );

        return compact('folders', 'files');
    }

    /**
     * @return array{
     *   folders:list<array{
     *     public_id:string,
     *     name:string,
     *     path:string,
     *     target_url:string
     *   }>,
     *   files:list<array{
     *     public_id:string,
     *     name:string,
     *     path:string,
     *     folder_public_id:string|null,
     *     target_url:string
     *   }>
     * }
     */
    public function searchSuggestions(User $user, string $query, int $limit = 8): array
    {
        $needle = trim($query);
        if ($needle === '') {
            return [
                'folders' => [],
                'files' => [],
            ];
        }

        $tokens = $this->searchTokens($needle);
        if ($tokens === []) {
            return [
                'folders' => [],
                'files' => [],
            ];
        }

        $user->loadMissing('employee');
        $candidateLimit = max(40, $limit * 10);

        $folderCandidates = Folder::query()
            ->where('is_deleted', false)
            ->where(function (Builder $query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $query->where(function (Builder $tokenQuery) use ($token): void {
                        $tokenQuery->where('name', 'like', "%{$token}%")
                            ->orWhere('path', 'like', "%{$token}%");
                    });
                }
            })
            ->orderBy('name')
            ->limit($candidateLimit)
            ->get();

        $folders = $folderCandidates
            ->filter(fn (Folder $folder): bool => $user->can('view', $folder))
            ->map(function (Folder $folder) use ($needle): array {
                $path = trim((string) ($folder->path ?? ''));
                $pathLabel = $path !== '' ? $path : $folder->name;

                return [
                    'score' => $this->fuzzyScore($needle, "{$folder->name} {$pathLabel}"),
                    'public_id' => $folder->public_id,
                    'name' => $folder->name,
                    'path' => $pathLabel,
                    'target_url' => "/folders/{$folder->public_id}",
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->take($limit)
            ->map(fn (array $folder): array => [
                'public_id' => $folder['public_id'],
                'name' => $folder['name'],
                'path' => $folder['path'],
                'target_url' => $folder['target_url'],
            ])
            ->all();

        $fileCandidates = File::query()
            ->where('is_deleted', false)
            ->where(function (Builder $query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $query->where(function (Builder $tokenQuery) use ($token): void {
                        $tokenQuery->where('original_name', 'like', "%{$token}%")
                            ->orWhere('mime_type', 'like', "%{$token}%")
                            ->orWhereHas('folder', function (Builder $folderQuery) use ($token): void {
                                $folderQuery->where('name', 'like', "%{$token}%")
                                    ->orWhere('path', 'like', "%{$token}%");
                            });
                    });
                }
            })
            ->with(['folder:id,public_id,name,path,is_deleted'])
            ->orderByDesc('updated_at')
            ->limit($candidateLimit)
            ->get();

        $files = $fileCandidates
            ->filter(function (File $file) use ($user): bool {
                if ($file->folder?->is_deleted) {
                    return false;
                }

                return $user->can('view', $file);
            })
            ->map(function (File $file) use ($needle): array {
                $folderPublicId = $file->folder?->public_id;
                $folderPath = trim((string) ($file->folder?->path ?? ''));
                $targetUrl = $folderPublicId !== null
                    ? "/folders/{$folderPublicId}"
                    : '/my-files';

                return [
                    'score' => $this->fuzzyScore(
                        $needle,
                        "{$file->original_name} {$folderPath} {$file->mime_type}",
                    ),
                    'public_id' => $file->public_id,
                    'name' => $file->original_name,
                    'path' => $folderPath !== '' ? $folderPath : 'My Files',
                    'folder_public_id' => $folderPublicId,
                    'target_url' => $targetUrl,
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->take($limit)
            ->map(fn (array $file): array => [
                'public_id' => $file['public_id'],
                'name' => $file['name'],
                'path' => $file['path'],
                'folder_public_id' => $file['folder_public_id'],
                'target_url' => $file['target_url'],
            ])
            ->all();

        return [
            'folders' => $folders,
            'files' => $files,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{folders:\Illuminate\Support\Collection<int, Folder>, files:LengthAwarePaginator}
     */
    public function departmentFiles(User $user, array $filters): array
    {
        $departmentId = $user->employee?->department_id;
        $searchNeedle = $this->searchNeedle($filters);

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
            ->whereHas('folder', function (Builder $query): void {
                $query->where('visibility', 'shared');
            })
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

        $searchableFolderIds = array_values(array_unique(array_merge(
            $departmentFolderSubtreeIds,
            $sharedFolderSubtreeIds,
        )));

        if ($searchNeedle === null) {
            $folders = $departmentFolders
                ->concat($sharedFolders)
                ->unique('id')
                ->sortBy('name')
                ->values();
        } elseif ($searchableFolderIds === []) {
            $folders = new Collection;
        } else {
            $folders = Folder::query()
                ->whereIn('id', $searchableFolderIds)
                ->where('is_deleted', false)
                ->tap(fn (Builder $query) => $this->applyFolderFilters($query, $filters))
                ->with([
                    'owner',
                    'permissions.user.employee',
                ])
                ->orderBy('path')
                ->orderBy('name')
                ->get();
        }

        $folders = $folders->map(function (Folder $folder) use ($departmentId, $user): Folder {
            $folder->setAttribute(
                'access',
                $this->resolveDepartmentFolderAccess($user, $folder, $departmentId),
            );

            return $folder;
        });
        $folders = $this->appendSharingMetadataToFolders($folders);
        $folders = $this->appendSourceMetadataToFolders($folders, $user, $departmentId);

        $folderSubtreeIdsToHideFromRootFiles = array_values(array_unique(array_merge(
            $departmentFolderSubtreeIds,
            $sharedFolderSubtreeIds,
        )));

        $files = File::query()
            ->where('is_deleted', false)
            ->where(function (Builder $scopeQuery) use ($departmentId, $searchNeedle, $searchableFolderIds): void {
                $hasScope = false;

                if ($departmentId !== null) {
                    $scopeQuery->where(function (Builder $departmentQuery) use ($departmentId): void {
                        $departmentQuery->where('department_id', $departmentId)
                            ->where('visibility', 'department');
                    });
                    $hasScope = true;
                }

                if ($searchNeedle !== null && $searchableFolderIds !== []) {
                    if ($hasScope) {
                        $scopeQuery->orWhereIn('folder_id', $searchableFolderIds);
                    } else {
                        $scopeQuery->whereIn('folder_id', $searchableFolderIds);
                        $hasScope = true;
                    }
                }

                if (! $hasScope) {
                    $scopeQuery->whereRaw('1 = 0');
                }
            })
            ->when($searchNeedle === null && $folderSubtreeIdsToHideFromRootFiles !== [], function (Builder $query) use ($folderSubtreeIdsToHideFromRootFiles): void {
                $query->whereNotIn('folder_id', $folderSubtreeIdsToHideFromRootFiles);
            })
            ->with([
                'owner',
                'folder:id,public_id,name,path,visibility,parent_id',
                'department',
                'permissions' => fn ($query) => $query->where('user_id', $user->id),
            ])
            ->tap(fn (Builder $query) => $this->applyFileFilters($query, $filters))
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        $files->setCollection(
            $this->appendSourceMetadataToFiles(
                $this->appendSharingMetadataToFiles(
                    $files->getCollection()->map(function (File $file) use ($departmentId, $user): File {
                        $directPermission = $file->permissions->first();
                        $isDepartmentVisible = $departmentId !== null
                            && $file->department_id === $departmentId
                            && $file->visibility === 'department';
                        $hasDirectPermission = $directPermission !== null;
                        $folderId = $file->folder?->id;
                        $folderAccess = is_int($folderId)
                            ? $this->folderAccessByFolderId($user, $folderId)
                            : [
                                'can_view' => false,
                                'can_upload' => false,
                                'can_edit' => false,
                                'can_delete' => false,
                            ];

                        $file->setAttribute('access', [
                            'can_view' => $hasDirectPermission
                                ? (bool) $directPermission->can_view
                                : ($isDepartmentVisible || (bool) $folderAccess['can_view']),
                            'can_download' => $hasDirectPermission
                                ? (bool) $directPermission->can_download
                                : (
                                    $isDepartmentVisible
                                        ? $user->can('files.download')
                                        : (bool) $folderAccess['can_view']
                                ),
                            'can_edit' => $hasDirectPermission
                                ? (bool) $directPermission->can_edit
                                : (
                                    $isDepartmentVisible
                                        ? $user->can('files.update')
                                        : (bool) $folderAccess['can_edit']
                                ),
                            'can_delete' => $hasDirectPermission
                                ? (bool) $directPermission->can_delete
                                : (
                                    $isDepartmentVisible
                                        ? $user->can('files.delete')
                                        : (bool) $folderAccess['can_delete']
                                ),
                        ]);

                        return $file;
                    }),
                ),
                $user,
                $departmentId,
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
        $departmentId = $user->employee?->department_id;
        $searchNeedle = $this->searchNeedle($filters);
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
                ->values();

            $sharedFolderSubtreeIds = $this->collectSubtreeFolderIdsForMany($activeSharedFolderIds);
        }

        if ($searchNeedle !== null) {
            if ($sharedFolderSubtreeIds === []) {
                $folders = new Collection;
            } else {
                $folders = Folder::query()
                    ->whereIn('id', $sharedFolderSubtreeIds)
                    ->where('is_deleted', false)
                    ->tap(fn (Builder $query) => $this->applyFolderFilters($query, $filters))
                    ->with([
                        'owner',
                        'permissions' => fn ($query) => $query->where('user_id', $user->id),
                    ])
                    ->orderBy('path')
                    ->orderBy('name')
                    ->get();
            }
        }

        $folders = $folders->map(function (Folder $folder) use ($user): Folder {
            $directPermission = $folder->permissions->first();
            $folderAccess = $this->folderAccessForUser($user, $folder);

            $folder->setAttribute('access', [
                'can_view' => (bool) ($directPermission?->can_view ?? $folderAccess['can_view']),
                'can_upload' => (bool) ($directPermission?->can_upload ?? $folderAccess['can_upload']),
                'can_edit' => (bool) ($directPermission?->can_edit ?? $folderAccess['can_edit']),
                'can_delete' => (bool) ($directPermission?->can_delete ?? $folderAccess['can_delete']),
            ]);

            return $folder;
        });
        $folders = $this->appendSharingMetadataToFolders($folders);
        $folders = $this->appendSourceMetadataToFolders($folders, $user, $departmentId);

        $files = File::query()
            ->where('is_deleted', false)
            ->where(function (Builder $scopeQuery) use ($searchNeedle, $sharedFolderSubtreeIds, $user): void {
                $scopeQuery->whereIn('id', function ($query) use ($user): void {
                    $query->select('file_id')
                        ->from((new FilePermission)->getTable())
                        ->where('user_id', $user->id)
                        ->where('can_view', true);
                });

                if ($searchNeedle !== null && $sharedFolderSubtreeIds !== []) {
                    $scopeQuery->orWhereIn('folder_id', $sharedFolderSubtreeIds);
                }
            })
            ->when($searchNeedle === null && $sharedFolderSubtreeIds !== [], function (Builder $query) use ($sharedFolderSubtreeIds): void {
                $query->whereNotIn('folder_id', $sharedFolderSubtreeIds);
            })
            ->with([
                'owner',
                'department',
                'permissions' => fn ($query) => $query->where('user_id', $user->id),
                'folder:id,public_id,name,path,visibility,parent_id',
            ])
            ->tap(fn (Builder $query) => $this->applyFileFilters($query, $filters))
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        $files->setCollection(
            $this->appendSourceMetadataToFiles(
                $this->appendSharingMetadataToFiles($files->getCollection()->map(function (File $file) use ($user): File {
                    $directPermission = $file->permissions->first();
                    $folderId = $file->folder?->id;
                    $folderAccess = is_int($folderId)
                        ? $this->folderAccessByFolderId($user, $folderId)
                        : [
                            'can_view' => false,
                            'can_upload' => false,
                            'can_edit' => false,
                            'can_delete' => false,
                        ];

                    $file->setAttribute('access', [
                        'can_view' => (bool) ($directPermission?->can_view ?? $folderAccess['can_view']),
                        'can_download' => (bool) ($directPermission?->can_download ?? $folderAccess['can_view']),
                        'can_edit' => (bool) ($directPermission?->can_edit ?? $folderAccess['can_edit']),
                        'can_delete' => (bool) ($directPermission?->can_delete ?? $folderAccess['can_delete']),
                    ]);

                    return $file;
                })),
                $user,
                $departmentId,
            ),
        );

        return compact('folders', 'files');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{children:\Illuminate\Support\Collection<int, Folder>, files:LengthAwarePaginator}
     */
    public function folderContents(
        User|Folder $userOrFolder,
        Folder|array $folderOrFilters,
        ?array $filters = null,
    ): array
    {
        [$user, $folder, $filters] = $this->resolveFolderContentsArguments(
            $userOrFolder,
            $folderOrFilters,
            $filters,
        );

        $folderAccess = $this->folderAccessForUser($user, $folder);
        $searchNeedle = $this->searchNeedle($filters);
        $subtreeFolderIds = $searchNeedle !== null
            ? $this->collectSubtreeFolderIds($folder->id)
            : [];
        $visibleFolderIds = array_values(array_filter(
            $subtreeFolderIds,
            fn (int $id): bool => $id !== $folder->id,
        ));

        $childrenQuery = Folder::query()
            ->where('is_deleted', false)
            ->with([
                'owner',
                'permissions.user.employee',
            ]);

        if ($searchNeedle === null) {
            $childrenQuery
                ->where('parent_id', $folder->id)
                ->orderBy('name');
        } elseif ($visibleFolderIds === []) {
            $childrenQuery->whereRaw('1 = 0');
        } else {
            $childrenQuery
                ->whereIn('id', $visibleFolderIds)
                ->tap(fn (Builder $query) => $this->applyFolderFilters($query, $filters))
                ->orderBy('path')
                ->orderBy('name');
        }

        $children = $childrenQuery->get();
        $children = $this->appendSourceMetadataToFolders(
            $this->appendSharingMetadataToFolders($children)->map(
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
                            ($isSameDepartment && $user->can('folders.delete')) ||
                            (bool) ($directPermission?->can_delete ?? false) ||
                            (bool) $folderAccess['can_delete'],
                    ]);

                    return $child;
                },
            ),
            $user,
            $user->employee?->department_id,
        );

        $filesQuery = File::query()
            ->where('is_deleted', false)
            ->with([
                'owner',
                'folder',
                'department',
                'permissions.user.employee',
            ]);

        if ($searchNeedle === null) {
            $filesQuery->where('folder_id', $folder->id);
        } elseif ($subtreeFolderIds === []) {
            $filesQuery->whereRaw('1 = 0');
        } else {
            $filesQuery->whereIn('folder_id', $subtreeFolderIds);
        }

        $files = $filesQuery
            ->tap(fn (Builder $query) => $this->applyFileFilters($query, $filters))
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
        $files->setCollection(
            $this->appendSourceMetadataToFiles(
                $this->appendSharingMetadataToFiles($files->getCollection())->map(
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
                                ($isSameDepartment && $user->can('files.delete')) ||
                                (bool) ($directPermission?->can_delete ?? false) ||
                                (bool) $folderAccess['can_delete'],
                        ]);

                        return $file;
                    },
                ),
                $user,
                $user->employee?->department_id,
            ),
        );

        return compact('children', 'files');
    }

    /**
     * @param  User|Folder  $userOrFolder
     * @param  Folder|array<string, mixed>  $folderOrFilters
     * @param  array<string, mixed>|null  $filters
     * @return array{0:User,1:Folder,2:array<string, mixed>}
     */
    private function resolveFolderContentsArguments(
        User|Folder $userOrFolder,
        Folder|array $folderOrFilters,
        ?array $filters,
    ): array {
        if ($userOrFolder instanceof User) {
            if (! $folderOrFilters instanceof Folder) {
                throw new \InvalidArgumentException(
                    'Expected folder argument when first parameter is a user.',
                );
            }

            return [$userOrFolder, $folderOrFilters, $filters ?? []];
        }

        if (! is_array($folderOrFilters)) {
            throw new \InvalidArgumentException(
                'Expected filters array when first parameter is a folder.',
            );
        }

        $ownerId = $userOrFolder->owner_user_id;
        if ($ownerId === null) {
            throw new \InvalidArgumentException(
                'Cannot infer user for folder contents when folder has no owner.',
            );
        }

        return [
            User::query()->findOrFail($ownerId),
            $userOrFolder,
            $folderOrFilters,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{folders:LengthAwarePaginator, files:LengthAwarePaginator}
     */
    public function trash(User $user, array $filters): array
    {
        $searchNeedle = $this->searchNeedle($filters);

        $foldersQuery = Folder::query()
            ->where('is_deleted', true)
            ->tap(fn (Builder $query) => $this->applyTrashScope($query, $user))
            ->with('owner');

        if ($searchNeedle === null) {
            $foldersQuery->where(function (Builder $query): void {
                $query->whereNull('parent_id')
                    ->orWhereHas('parent', fn (Builder $parentQuery) => $parentQuery->where('is_deleted', false));
            });
        } else {
            $foldersQuery->tap(fn (Builder $query) => $this->applyFolderFilters($query, $filters));
        }

        $folders = $foldersQuery
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

        $filesQuery = File::query()
            ->where('is_deleted', true)
            ->tap(fn (Builder $query) => $this->applyTrashScope($query, $user))
            ->with(['owner', 'folder:id,public_id,name,path,visibility,is_deleted']);

        if ($searchNeedle === null) {
            $filesQuery->whereHas('folder', fn (Builder $folderQuery) => $folderQuery->where('is_deleted', false));
        }

        $files = $filesQuery
            ->tap(fn (Builder $query) => $this->applyFileFilters($query, $filters))
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

        $searchNeedle = $this->searchNeedle($filters);
        $subtreeFolderIds = $searchNeedle !== null
            ? $this->collectSubtreeFolderIds($record->id)
            : [];
        $visibleFolderIds = array_values(array_filter(
            $subtreeFolderIds,
            fn (int $id): bool => $id !== $record->id,
        ));

        $childrenQuery = Folder::query()
            ->where('is_deleted', true)
            ->tap(fn (Builder $query) => $this->applyTrashScope($query, $user))
            ->with('owner');

        if ($searchNeedle === null) {
            $childrenQuery
                ->where('parent_id', $record->id)
                ->orderBy('name');
        } elseif ($visibleFolderIds === []) {
            $childrenQuery->whereRaw('1 = 0');
        } else {
            $childrenQuery
                ->whereIn('id', $visibleFolderIds)
                ->tap(fn (Builder $query) => $this->applyFolderFilters($query, $filters))
                ->orderBy('path')
                ->orderBy('name');
        }

        $children = $childrenQuery
            ->get()
            ->map(function (Folder $child): Folder {
                $child->setAttribute(
                    'trashed_files_count',
                    $this->countTrashedFilesInSubtree($child),
                );

                return $child;
            });

        $filesQuery = File::query()
            ->where('is_deleted', true)
            ->tap(fn (Builder $query) => $this->applyTrashScope($query, $user))
            ->with(['owner', 'folder:id,public_id,name,path,visibility,is_deleted']);

        if ($searchNeedle === null) {
            $filesQuery->where('folder_id', $record->id);
        } elseif ($subtreeFolderIds === []) {
            $filesQuery->whereRaw('1 = 0');
        } else {
            $filesQuery->whereIn('folder_id', $subtreeFolderIds);
        }

        $files = $filesQuery
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
    private function searchTokens(string $query): array
    {
        $tokens = preg_split('/\s+/', strtolower(trim($query)));
        if ($tokens === false) {
            return [];
        }

        return array_values(array_unique(array_filter($tokens, fn ($token): bool => $token !== '')));
    }

    private function fuzzyScore(string $needle, string $candidate): int
    {
        $normalize = static function (string $value): string {
            $normalized = strtolower($value);

            return (string) preg_replace('/[^a-z0-9]+/', '', $normalized);
        };

        $query = $normalize($needle);
        $text = $normalize($candidate);
        if ($query === '' || $text === '') {
            return 0;
        }

        $containsPos = strpos($text, $query);
        if ($containsPos !== false) {
            return max(1, 1200 - ($containsPos * 6) - abs(strlen($text) - strlen($query)));
        }

        $cursor = 0;
        $gapPenalty = 0;
        $queryLength = strlen($query);

        for ($index = 0; $index < $queryLength; $index++) {
            $char = $query[$index];
            $foundAt = strpos($text, $char, $cursor);

            if ($foundAt === false) {
                return 0;
            }

            $gapPenalty += max(0, $foundAt - $cursor);
            $cursor = $foundAt + 1;
        }

        return max(1, 600 - $gapPenalty - (strlen($text) - $queryLength));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function searchNeedle(array $filters): ?string
    {
        $needle = trim((string) ($filters['q'] ?? ''));

        return $needle === '' ? null : $needle;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFolderFilters(Builder $query, array $filters): void
    {
        $needle = $this->searchNeedle($filters);
        if ($needle === null) {
            return;
        }

        $query->where(function (Builder $inner) use ($needle): void {
            $inner->where('name', 'like', "%{$needle}%")
                ->orWhere('path', 'like', "%{$needle}%");
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFileFilters(Builder $query, array $filters): void
    {
        $needle = $this->searchNeedle($filters);
        if ($needle !== null) {
            $query->where(function (Builder $inner) use ($needle): void {
                $inner->where('original_name', 'like', "%{$needle}%")
                    ->orWhere('mime_type', 'like', "%{$needle}%")
                    ->orWhereHas('folder', function (Builder $folderQuery) use ($needle): void {
                        $folderQuery->where('name', 'like', "%{$needle}%")
                            ->orWhere('path', 'like', "%{$needle}%");
                    });
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

    /**
     * @return array{can_view:bool,can_upload:bool,can_edit:bool,can_delete:bool}
     */
    private function resolveDepartmentFolderAccess(
        User $user,
        Folder $folder,
        ?int $departmentId,
    ): array {
        $directPermission = $folder->permissions
            ->first(fn (FolderPermission $permission): bool => $permission->user_id === $user->id);
        $isDepartmentVisible = $departmentId !== null
            && $folder->department_id === $departmentId
            && $folder->visibility === 'department';
        $hasDirectPermission = $directPermission !== null;
        $folderAccess = $this->folderAccessForUser($user, $folder);

        return [
            'can_view' => $hasDirectPermission
                ? (bool) $directPermission->can_view
                : ($isDepartmentVisible || (bool) $folderAccess['can_view']),
            'can_upload' => $hasDirectPermission
                ? (bool) ($directPermission->can_upload || $directPermission->can_edit)
                : (
                    $isDepartmentVisible
                        ? $user->can('files.upload')
                        : ((bool) $folderAccess['can_upload'] || (bool) $folderAccess['can_edit'])
                ),
            'can_edit' => $hasDirectPermission
                ? (bool) $directPermission->can_edit
                : (
                    $isDepartmentVisible
                        ? $user->can('folders.update')
                        : (bool) $folderAccess['can_edit']
                ),
            'can_delete' => $hasDirectPermission
                ? (bool) $directPermission->can_delete
                : (
                    $isDepartmentVisible
                        ? $user->can('folders.delete')
                        : (bool) $folderAccess['can_delete']
                ),
        ];
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
        return $this->folderAccessByFolderId($user, $folder->id);
    }

    /**
     * @return array{can_view:bool,can_upload:bool,can_edit:bool,can_delete:bool}
     */
    private function folderAccessByFolderId(User $user, int $folderId): array
    {
        $ancestorIds = $this->ancestorFolderIds($folderId);

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
     * @param  Collection<int, Folder>  $folders
     * @return Collection<int, Folder>
     */
    private function appendSourceMetadataToFolders(
        Collection $folders,
        User $viewer,
        ?int $viewerDepartmentId = null,
    ): Collection {
        $departmentId = $viewerDepartmentId ?? $viewer->employee?->department_id;

        return $folders->map(function (Folder $folder) use ($departmentId, $viewer): Folder {
            if ($folder->owner_user_id === $viewer->id) {
                $folder->setAttribute('source', [
                    'scope' => 'my_files',
                    'label' => 'My Files',
                    'detail' => 'Owned by you',
                ]);

                return $folder;
            }

            if (
                $departmentId !== null &&
                $folder->department_id === $departmentId &&
                $folder->visibility === 'department'
            ) {
                $folder->setAttribute('source', [
                    'scope' => 'department_files',
                    'label' => 'Department Files',
                    'detail' => 'Shared with your department',
                ]);

                return $folder;
            }

            $ownerEmail = trim((string) ($folder->owner?->email ?? ''));
            $folder->setAttribute('source', [
                'scope' => 'shared_with_me',
                'label' => 'Shared With Me',
                'detail' => $ownerEmail !== '' ? "Shared by {$ownerEmail}" : 'Shared with you',
            ]);

            return $folder;
        });
    }

    /**
     * @param  Collection<int, File>  $files
     * @return Collection<int, File>
     */
    private function appendSourceMetadataToFiles(
        Collection $files,
        User $viewer,
        ?int $viewerDepartmentId = null,
    ): Collection {
        $departmentId = $viewerDepartmentId ?? $viewer->employee?->department_id;

        return $files->map(function (File $file) use ($departmentId, $viewer): File {
            if ($file->owner_user_id === $viewer->id) {
                $file->setAttribute('source', [
                    'scope' => 'my_files',
                    'label' => 'My Files',
                    'detail' => 'Owned by you',
                ]);

                return $file;
            }

            if (
                $departmentId !== null &&
                $file->department_id === $departmentId &&
                $file->visibility === 'department'
            ) {
                $departmentName = trim((string) ($file->department?->name ?? ''));

                $file->setAttribute('source', [
                    'scope' => 'department_files',
                    'label' => 'Department Files',
                    'detail' => $departmentName !== ''
                        ? "Shared with {$departmentName}"
                        : 'Shared with your department',
                ]);

                return $file;
            }

            $ownerEmail = trim((string) ($file->owner?->email ?? ''));
            $file->setAttribute('source', [
                'scope' => 'shared_with_me',
                'label' => 'Shared With Me',
                'detail' => $ownerEmail !== '' ? "Shared by {$ownerEmail}" : 'Shared with you',
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
