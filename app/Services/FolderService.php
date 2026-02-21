<?php

namespace App\Services;

use App\Models\File;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class FolderService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly StorageDiskResolver $storageDiskResolver,
    ) {
    }

    public function downloadArchive(User $actor, Folder $folder, Request $request): BinaryFileResponse
    {
        $record = Folder::query()
            ->where('id', $folder->id)
            ->where('is_deleted', false)
            ->firstOrFail();

        $folderIds = $this->collectSubtreeFolderIds($record->id);
        $folders = Folder::query()
            ->whereIn('id', $folderIds)
            ->where('is_deleted', false)
            ->get(['id', 'parent_id', 'name']);
        $relativeFolderPaths = $this->buildRelativeFolderPaths($record, $folders);
        $rootArchivePath = $relativeFolderPaths[$record->id] ?? $this->sanitizeArchiveSegment($record->name, 'folder');

        $files = File::query()
            ->whereIn('folder_id', $folderIds)
            ->where('is_deleted', false)
            ->orderBy('folder_id')
            ->orderBy('original_name')
            ->get();

        foreach ($files as $file) {
            if (! $actor->can('download', $file)) {
                throw new AuthorizationException('You are not authorized to download one or more files in this folder.');
            }
        }

        $tempZipPath = $this->createTemporaryZipPath();
        $zip = new ZipArchive;
        $result = $zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new RuntimeException('Unable to create a folder archive for download.');
        }

        $stagedPaths = [];
        $usedEntryPaths = [];

        try {
            foreach ($relativeFolderPaths as $relativeFolderPath) {
                if (! $zip->addEmptyDir($relativeFolderPath)) {
                    throw new RuntimeException('Unable to add a folder to the download archive.');
                }
            }

            foreach ($files as $file) {
                $folderPath = $relativeFolderPaths[$file->folder_id] ?? $rootArchivePath;
                $entryName = $this->sanitizeArchiveSegment((string) $file->original_name, 'file');
                $entryPath = trim("{$folderPath}/{$entryName}", '/');
                $entryPath = $this->ensureUniqueArchiveEntryPath($entryPath, $usedEntryPaths);

                $disk = $this->storageDiskResolver->resolve($file->storage_disk);
                $storage = Storage::disk($disk);

                if (! $storage->exists($file->storage_path)) {
                    abort(404, 'One or more files in this folder are not available for download.');
                }

                $driver = (string) config("filesystems.disks.{$disk}.driver", 'local');
                if ($driver === 'local') {
                    $absolutePath = $storage->path($file->storage_path);
                    if (! $zip->addFile($absolutePath, $entryPath)) {
                        throw new RuntimeException('Unable to add a file to the download archive.');
                    }

                    continue;
                }

                $sourceStream = $storage->readStream($file->storage_path);
                if ($sourceStream === false) {
                    throw new RuntimeException('Unable to read a file while building the download archive.');
                }

                $stagedPath = tempnam(sys_get_temp_dir(), 'fm-zip-entry-');
                if ($stagedPath === false) {
                    fclose($sourceStream);
                    throw new RuntimeException('Unable to prepare temporary storage for archive generation.');
                }

                $targetStream = fopen($stagedPath, 'wb');
                if ($targetStream === false) {
                    fclose($sourceStream);
                    @unlink($stagedPath);
                    throw new RuntimeException('Unable to write temporary archive content.');
                }

                stream_copy_to_stream($sourceStream, $targetStream);
                fclose($sourceStream);
                fclose($targetStream);

                if (! $zip->addFile($stagedPath, $entryPath)) {
                    @unlink($stagedPath);
                    throw new RuntimeException('Unable to add a file to the download archive.');
                }

                $stagedPaths[] = $stagedPath;
            }

            if (! $zip->close()) {
                throw new RuntimeException('Unable to finalize the download archive.');
            }
        } catch (\Throwable $exception) {
            $zip->close();
            foreach ($stagedPaths as $stagedPath) {
                @unlink($stagedPath);
            }
            @unlink($tempZipPath);

            throw $exception;
        }

        foreach ($stagedPaths as $stagedPath) {
            @unlink($stagedPath);
        }

        $this->auditLogService->log(
            actor: $actor,
            action: 'folder.downloaded',
            entityType: 'folder',
            entityId: $record->id,
            meta: [
                'request_id' => $request->attributes->get('request_id'),
                'folder_public_id' => $record->public_id,
                'archive_file_count' => $files->count(),
            ],
            request: $request,
        );

        $downloadName = $this->sanitizeArchiveSegment($record->name, 'folder').'.zip';

        return response()
            ->download($tempZipPath, $downloadName, [
                'Content-Type' => 'application/zip',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, array $input, Request $request): Folder
    {
        return DB::transaction(function () use ($actor, $input, $request): Folder {
            $parent = null;
            if (! empty($input['parent_id'])) {
                $parent = Folder::query()->where('public_id', $input['parent_id'])->lockForUpdate()->firstOrFail();
            }

            $scope = $this->resolveScope($actor, $input, $parent);
            $name = trim((string) $input['name']);

            $conflict = Folder::query()
                ->where('parent_id', $parent?->id)
                ->where('name', $name)
                ->where('owner_user_id', $scope['owner_user_id'])
                ->where('department_id', $scope['department_id'])
                ->where('is_deleted', false)
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'name' => 'A folder with the same name already exists in this location.',
                ]);
            }

            $folder = Folder::query()->create([
                'parent_id' => $parent?->id,
                'name' => $name,
                'owner_user_id' => $scope['owner_user_id'],
                'department_id' => $scope['department_id'],
                'visibility' => $scope['visibility'],
                'path' => $this->buildFolderPath($parent, $name),
                'is_deleted' => false,
                'deleted_at' => null,
            ]);

            $this->auditLogService->log(
                actor: $actor,
                action: 'folder.created',
                entityType: 'folder',
                entityId: $folder->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'folder_public_id' => $folder->public_id,
                    'parent_public_id' => $parent?->public_id,
                    'visibility' => $folder->visibility,
                ],
                request: $request,
            );

            return $folder;
        });
    }

    public function softDelete(User $actor, Folder $folder, Request $request): Folder
    {
        return DB::transaction(function () use ($actor, $folder, $request): Folder {
            $record = Folder::query()->lockForUpdate()->findOrFail($folder->id);
            $folderIds = $this->collectSubtreeFolderIds($record->id, true);
            $now = now();

            Folder::query()
                ->whereIn('id', $folderIds)
                ->update([
                    'is_deleted' => true,
                    'deleted_at' => $now,
                ]);

            File::query()
                ->whereIn('folder_id', $folderIds)
                ->where('is_deleted', false)
                ->update([
                    'is_deleted' => true,
                    'deleted_at' => $now,
                ]);

            $this->auditLogService->log(
                actor: $actor,
                action: 'folder.deleted',
                entityType: 'folder',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'folder_public_id' => $record->public_id,
                    'subtree_count' => count($folderIds),
                    'idempotency_key' => $request->header('X-Idempotency-Key'),
                ],
                request: $request,
            );

            return $record->fresh();
        });
    }

    public function permanentlyDelete(User $actor, Folder $folder, Request $request): void
    {
        $pathsByDisk = [];

        DB::transaction(function () use ($actor, $folder, $request, &$pathsByDisk): void {
            $record = Folder::query()->lockForUpdate()->findOrFail($folder->id);

            if (! $record->is_deleted) {
                throw ValidationException::withMessages([
                    'folder' => 'Folder must be in trash before deleting forever.',
                ]);
            }

            $folderIds = $this->collectSubtreeFolderIds($record->id, true);

            $files = File::query()
                ->whereIn('folder_id', $folderIds)
                ->lockForUpdate()
                ->get();

            $fileIds = $files->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            foreach ($files as $item) {
                $disk = $this->storageDiskResolver->resolve($item->storage_disk);
                $pathsByDisk[$disk][] = $item->storage_path;
            }

            if ($fileIds !== []) {
                $versionPaths = FileVersion::query()
                    ->join('files', 'files.id', '=', 'file_versions.file_id')
                    ->whereIn('file_versions.file_id', $fileIds)
                    ->get([
                        'file_versions.storage_path as storage_path',
                        'files.storage_disk as storage_disk',
                    ]);

                foreach ($versionPaths as $version) {
                    $disk = $this->storageDiskResolver->resolve((string) $version->storage_disk);
                    $pathsByDisk[$disk][] = (string) $version->storage_path;
                }

                File::query()
                    ->whereIn('id', $fileIds)
                    ->delete();
            }

            Folder::query()
                ->whereIn('id', $folderIds)
                ->delete();

            $this->auditLogService->log(
                actor: $actor,
                action: 'folder.purged',
                entityType: 'folder',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'folder_public_id' => $record->public_id,
                    'subtree_count' => count($folderIds),
                    'purged_file_count' => count($fileIds),
                    'idempotency_key' => $request->header('X-Idempotency-Key'),
                ],
                request: $request,
            );
        });

        foreach ($pathsByDisk as $disk => $paths) {
            $uniquePaths = array_values(array_unique(array_filter($paths)));
            if ($uniquePaths === []) {
                continue;
            }

            Storage::disk($disk)->delete($uniquePaths);
        }
    }

    public function restore(User $actor, Folder $folder, Request $request): Folder
    {
        return DB::transaction(function () use ($actor, $folder, $request): Folder {
            $record = Folder::query()->lockForUpdate()->findOrFail($folder->id);
            $this->restoreParentFolders($record);
            $folderIds = $this->collectSubtreeFolderIds($record->id, true);

            $records = Folder::query()
                ->whereIn('id', $folderIds)
                ->orderBy('id')
                ->get();

            foreach ($records as $item) {
                $conflict = Folder::query()
                    ->where('parent_id', $item->parent_id)
                    ->where('name', $item->name)
                    ->where('owner_user_id', $item->owner_user_id)
                    ->where('department_id', $item->department_id)
                    ->where('is_deleted', false)
                    ->where('id', '!=', $item->id)
                    ->exists();

                if ($conflict) {
                    throw ValidationException::withMessages([
                        'folder' => 'Cannot restore folder subtree because a folder name conflict exists.',
                    ]);
                }
            }

            Folder::query()
                ->whereIn('id', $folderIds)
                ->update([
                    'is_deleted' => false,
                    'deleted_at' => null,
                ]);

            $files = File::query()
                ->whereIn('folder_id', $folderIds)
                ->where('is_deleted', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($files as $file) {
                $exists = File::query()
                    ->where('folder_id', $file->folder_id)
                    ->where('original_name', $file->original_name)
                    ->where('is_deleted', false)
                    ->where('id', '!=', $file->id)
                    ->exists();

                if ($exists) {
                    $file->original_name = $this->autoRenameFileName($file->folder_id, $file->original_name, $file->id);
                }

                $file->is_deleted = false;
                $file->deleted_at = null;
                $file->save();
            }

            $this->auditLogService->log(
                actor: $actor,
                action: 'folder.restored',
                entityType: 'folder',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'folder_public_id' => $record->public_id,
                    'subtree_count' => count($folderIds),
                    'restored_file_count' => $files->count(),
                    'idempotency_key' => $request->header('X-Idempotency-Key'),
                ],
                request: $request,
            );

            return $record->fresh();
        });
    }

    private function restoreParentFolders(Folder $record): void
    {
        $chain = [];
        $current = $record->parent_id === null
            ? null
            : Folder::query()->lockForUpdate()->find($record->parent_id);

        while ($current) {
            $chain[] = $current;
            if ($current->parent_id === null) {
                break;
            }

            $current = Folder::query()
                ->lockForUpdate()
                ->find($current->parent_id);
        }

        $chain = array_reverse($chain);

        foreach ($chain as $folder) {
            if (! $folder->is_deleted) {
                continue;
            }

            $conflict = Folder::query()
                ->where('parent_id', $folder->parent_id)
                ->where('name', $folder->name)
                ->where('owner_user_id', $folder->owner_user_id)
                ->where('department_id', $folder->department_id)
                ->where('is_deleted', false)
                ->where('id', '!=', $folder->id)
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'folder' => 'Cannot restore folder because a parent folder name conflict exists.',
                ]);
            }

            $folder->is_deleted = false;
            $folder->deleted_at = null;
            $folder->save();
        }
    }

    public function rename(User $actor, Folder $folder, string $name, Request $request): Folder
    {
        return DB::transaction(function () use ($actor, $folder, $name, $request): Folder {
            $record = Folder::query()->lockForUpdate()->findOrFail($folder->id);

            $duplicate = Folder::query()
                ->where('parent_id', $record->parent_id)
                ->where('name', $name)
                ->where('owner_user_id', $record->owner_user_id)
                ->where('department_id', $record->department_id)
                ->where('is_deleted', false)
                ->where('id', '!=', $record->id)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'name' => 'Another folder with this name already exists.',
                ]);
            }

            $record->update([
                'name' => $name,
                'path' => $this->buildFolderPath($record->parent, $name),
            ]);

            $this->auditLogService->log(
                actor: $actor,
                action: 'folder.renamed',
                entityType: 'folder',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'folder_public_id' => $record->public_id,
                    'name' => $name,
                ],
                request: $request,
            );

            return $record->fresh();
        });
    }

    public function move(User $actor, Folder $folder, Folder $destination, Request $request): Folder
    {
        return DB::transaction(function () use ($actor, $folder, $destination, $request): Folder {
            $record = Folder::query()->lockForUpdate()->findOrFail($folder->id);
            $target = Folder::query()->lockForUpdate()->findOrFail($destination->id);

            if ($record->id === $target->id) {
                throw ValidationException::withMessages([
                    'destination_folder_id' => 'Folder cannot be moved into itself.',
                ]);
            }

            $descendantIds = $this->collectSubtreeFolderIds($record->id, true);
            if (in_array($target->id, $descendantIds, true)) {
                throw ValidationException::withMessages([
                    'destination_folder_id' => 'Cannot move a folder into its own descendant.',
                ]);
            }

            if ($record->owner_user_id !== $target->owner_user_id || $record->department_id !== $target->department_id) {
                throw ValidationException::withMessages([
                    'destination_folder_id' => 'Destination folder is outside the allowed scope.',
                ]);
            }

            $duplicate = Folder::query()
                ->where('parent_id', $target->id)
                ->where('name', $record->name)
                ->where('owner_user_id', $record->owner_user_id)
                ->where('department_id', $record->department_id)
                ->where('is_deleted', false)
                ->where('id', '!=', $record->id)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'destination_folder_id' => 'A folder with the same name already exists in the destination.',
                ]);
            }

            $record->update([
                'parent_id' => $target->id,
                'path' => $this->buildFolderPath($target, $record->name),
            ]);

            $this->refreshSubtreePaths($record);

            $this->auditLogService->log(
                actor: $actor,
                action: 'folder.moved',
                entityType: 'folder',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'folder_public_id' => $record->public_id,
                    'destination_folder_public_id' => $target->public_id,
                ],
                request: $request,
            );

            return $record->fresh();
        });
    }

    /**
     * @return list<array{public_id:string,name:string}>
     */
    public function breadcrumbTrail(Folder $folder): array
    {
        $trail = [];

        $current = Folder::query()
            ->select(['id', 'parent_id', 'public_id', 'name'])
            ->find($folder->id);

        while ($current) {
            $trail[] = [
                'public_id' => $current->public_id,
                'name' => $current->name,
            ];

            if ($current->parent_id === null) {
                break;
            }

            $current = Folder::query()
                ->select(['id', 'parent_id', 'public_id', 'name'])
                ->find($current->parent_id);
        }

        return array_values(array_reverse($trail));
    }

    /**
     * @param  Collection<int, Folder>  $folders
     * @return array<int, string>
     */
    private function buildRelativeFolderPaths(Folder $root, Collection $folders): array
    {
        $paths = [
            $root->id => $this->sanitizeArchiveSegment($root->name, 'folder'),
        ];

        /** @var array<int, array<int, Folder>> $childrenByParent */
        $childrenByParent = [];
        foreach ($folders as $folder) {
            if ($folder->parent_id === null) {
                continue;
            }

            $childrenByParent[$folder->parent_id][] = $folder;
        }

        $queue = [$root->id];

        while ($queue !== []) {
            $parentId = array_shift($queue);
            if (! isset($paths[$parentId])) {
                continue;
            }

            $children = $childrenByParent[$parentId] ?? [];
            usort($children, fn (Folder $left, Folder $right): int => strcmp($left->name, $right->name));

            $usedSegments = [];
            foreach ($children as $child) {
                $segment = $this->sanitizeArchiveSegment($child->name, 'folder');
                $segment = $this->ensureUniqueArchiveSegment($segment, $usedSegments);
                $paths[$child->id] = trim($paths[$parentId].'/'.$segment, '/');
                $queue[] = $child->id;
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, true>  $usedSegments
     */
    private function ensureUniqueArchiveSegment(string $segment, array &$usedSegments): string
    {
        $candidate = $segment;
        $counter = 2;

        while (isset($usedSegments[strtolower($candidate)])) {
            $candidate = "{$segment}_{$counter}";
            $counter++;
        }

        $usedSegments[strtolower($candidate)] = true;

        return $candidate;
    }

    /**
     * @param  array<string, true>  $usedEntryPaths
     */
    private function ensureUniqueArchiveEntryPath(string $entryPath, array &$usedEntryPaths): string
    {
        $candidate = $entryPath;
        $counter = 2;

        while (isset($usedEntryPaths[strtolower($candidate)])) {
            $pathInfo = pathinfo($entryPath);
            $directory = $pathInfo['dirname'] ?? '';
            $fileName = $pathInfo['filename'] ?? 'file';
            $extension = isset($pathInfo['extension']) ? '.'.$pathInfo['extension'] : '';
            $nextFileName = "{$fileName}_{$counter}{$extension}";
            $candidate = $directory === '.' || $directory === ''
                ? $nextFileName
                : "{$directory}/{$nextFileName}";
            $counter++;
        }

        $usedEntryPaths[strtolower($candidate)] = true;

        return $candidate;
    }

    private function sanitizeArchiveSegment(string $value, string $fallback): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._ -]+/', '_', trim($value));
        $sanitized = trim((string) $sanitized, '. ');

        return $sanitized !== '' ? $sanitized : $fallback;
    }

    private function createTemporaryZipPath(): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'fm-folder-');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to allocate temporary storage for the folder archive.');
        }

        @unlink($tempPath);

        return $tempPath.'.zip';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{owner_user_id:int|null, department_id:int|null, visibility:string}
     */
    private function resolveScope(User $actor, array $input, ?Folder $parent): array
    {
        if ($parent) {
            if ($parent->department_id !== null && ! $actor->can('folders.create_department')) {
                throw ValidationException::withMessages([
                    'scope' => 'You are not allowed to create department folders.',
                ]);
            }

            return [
                // Department-scoped folders do not support a user owner in schema.
                'owner_user_id' => $parent->owner_user_id !== null ? $actor->id : null,
                'department_id' => $parent->department_id,
                'visibility' => $parent->visibility,
            ];
        }

        $scope = $input['scope'] ?? 'private';
        if ($scope === 'department') {
            if (! $actor->can('folders.create_department')) {
                throw ValidationException::withMessages([
                    'scope' => 'You are not allowed to create department folders.',
                ]);
            }

            $departmentId = $actor->employee?->department_id;
            if (! $departmentId) {
                throw ValidationException::withMessages([
                    'scope' => 'Department scope is not available for this user.',
                ]);
            }

            return [
                'owner_user_id' => null,
                'department_id' => $departmentId,
                'visibility' => 'department',
            ];
        }

        return [
            'owner_user_id' => $actor->id,
            'department_id' => null,
            'visibility' => 'private',
        ];
    }

    private function buildFolderPath(?Folder $parent, string $name): string
    {
        return $parent ? trim($parent->path.'/'.$name, '/') : $name;
    }

    /**
     * @return list<int>
     */
    private function collectSubtreeFolderIds(int $rootFolderId, bool $lock = false): array
    {
        $ids = [$rootFolderId];
        $cursor = [$rootFolderId];

        while ($cursor !== []) {
            $query = Folder::query()
                ->whereIn('parent_id', $cursor)
                ->select('id');

            if ($lock) {
                $query->lockForUpdate();
            }

            $next = $query->pluck('id')->map(static fn ($id) => (int) $id)->all();
            if ($next === []) {
                break;
            }

            $ids = array_merge($ids, $next);
            $cursor = $next;
        }

        return array_values(array_unique($ids));
    }

    private function refreshSubtreePaths(Folder $root): void
    {
        $stack = [$root->fresh(['children'])];

        while ($stack !== []) {
            /** @var Folder $node */
            $node = array_pop($stack);
            $children = $node->children()->orderBy('id')->get();

            foreach ($children as $child) {
                $child->path = $this->buildFolderPath($node, $child->name);
                $child->save();
                $stack[] = $child;
            }
        }
    }

    private function autoRenameFileName(int $folderId, string $originalName, ?int $excludeId = null): string
    {
        $dot = strrpos($originalName, '.');
        $base = $dot === false ? $originalName : substr($originalName, 0, $dot);
        $ext = $dot === false ? '' : substr($originalName, $dot);

        $counter = 1;
        do {
            $candidate = "{$base} (restored {$counter}){$ext}";
            $query = File::query()
                ->where('folder_id', $folderId)
                ->where('original_name', $candidate)
                ->where('is_deleted', false);
            if ($excludeId !== null) {
                $query->where('id', '!=', $excludeId);
            }
            $exists = $query->exists();
            $counter++;
        } while ($exists);

        return $candidate;
    }
}
