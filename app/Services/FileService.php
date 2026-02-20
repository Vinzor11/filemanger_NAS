<?php

namespace App\Services;

use App\Jobs\ComputeChecksumJob;
use App\Jobs\VirusScanJob;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class FileService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly StorageDiskResolver $storageDiskResolver,
    ) {
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function uploadToFolder(
        User $actor,
        Folder $folder,
        UploadedFile $uploadedFile,
        array $options,
        Request $request,
    ): File {
        $disk = $this->storageDiskResolver->resolve($options['storage_disk'] ?? null);
        $requestId = (string) ($request->attributes->get('request_id') ?? Str::uuid());
        $extension = strtolower($uploadedFile->getClientOriginalExtension());
        $storedName = (string) Str::uuid().($extension ? ".{$extension}" : '');
        $tmpPath = "tmp/{$requestId}/{$storedName}";
        Storage::disk($disk)->putFileAs("tmp/{$requestId}", $uploadedFile, $storedName);

        $createdPath = null;

        try {
            $file = DB::transaction(function () use (
                $actor,
                $folder,
                $uploadedFile,
                $options,
                $request,
                $disk,
                $storedName,
                $tmpPath,
                &$createdPath,
                $extension
            ): File {
                $folder = Folder::query()->lockForUpdate()->findOrFail($folder->id);

                $originalName = trim((string) ($options['original_name'] ?? $uploadedFile->getClientOriginalName()));
                $duplicateMode = $options['duplicate_mode'] ?? 'fail';

                $existing = File::query()
                    ->where('folder_id', $folder->id)
                    ->where('original_name', $originalName)
                    ->where('is_deleted', false)
                    ->lockForUpdate()
                    ->first();

                if ($existing && $duplicateMode === 'fail') {
                    throw ValidationException::withMessages([
                        'file' => 'A file with this name already exists. Choose replace or rename.',
                    ]);
                }

                if ($existing && $duplicateMode === 'auto_rename') {
                    $originalName = $this->autoRename($folder->id, $originalName);
                    $existing = null;
                }

                $finalPath = $this->buildFinalPath($folder, $storedName);
                $createdPath = $finalPath;
                Storage::disk($disk)->move($tmpPath, $finalPath);

                if ($existing && $duplicateMode === 'replace') {
                    $nextVersion = (int) FileVersion::query()
                        ->where('file_id', $existing->id)
                        ->max('version_no') + 1;

                    FileVersion::query()->create([
                        'file_id' => $existing->id,
                        'version_no' => $nextVersion,
                        'stored_name' => $existing->stored_name,
                        'storage_path' => $existing->storage_path,
                        'size_bytes' => $existing->size_bytes,
                        'checksum_sha256' => $existing->checksum_sha256,
                        'created_by' => $actor->id,
                        'created_at' => now(),
                    ]);

                    $existing->update([
                        'stored_name' => $storedName,
                        'storage_path' => $finalPath,
                        'extension' => $extension ?: null,
                        'mime_type' => $uploadedFile->getClientMimeType(),
                        'size_bytes' => $uploadedFile->getSize() ?: 0,
                        'checksum_sha256' => null,
                        'storage_disk' => $disk,
                        'visibility' => $folder->visibility,
                        'owner_user_id' => $folder->owner_user_id ?: $actor->id,
                        'department_id' => $folder->department_id,
                        'is_deleted' => false,
                        'deleted_at' => null,
                    ]);

                    $this->auditLogService->log(
                        actor: $actor,
                        action: 'file.replaced',
                        entityType: 'file',
                        entityId: $existing->id,
                        meta: [
                            'request_id' => $request->attributes->get('request_id'),
                            'file_public_id' => $existing->public_id,
                            'folder_public_id' => $folder->public_id,
                            'stored_name' => $storedName,
                            'storage_path' => $finalPath,
                            'idempotency_key' => $request->header('X-Idempotency-Key'),
                        ],
                        request: $request,
                    );

                    return $existing->fresh(['folder']);
                }

                $file = File::query()->create([
                    'folder_id' => $folder->id,
                    'owner_user_id' => $folder->owner_user_id ?: $actor->id,
                    'department_id' => $folder->department_id,
                    'original_name' => $originalName,
                    'stored_name' => $storedName,
                    'extension' => $extension ?: null,
                    'mime_type' => $uploadedFile->getClientMimeType(),
                    'size_bytes' => $uploadedFile->getSize() ?: 0,
                    'checksum_sha256' => null,
                    'storage_disk' => $disk,
                    'storage_path' => $finalPath,
                    'visibility' => $folder->visibility,
                    'is_deleted' => false,
                    'deleted_at' => null,
                ]);

                $this->auditLogService->log(
                    actor: $actor,
                    action: 'file.uploaded',
                    entityType: 'file',
                    entityId: $file->id,
                    meta: [
                        'request_id' => $request->attributes->get('request_id'),
                        'file_public_id' => $file->public_id,
                        'folder_public_id' => $folder->public_id,
                        'original_name' => $file->original_name,
                        'stored_name' => $storedName,
                        'size_bytes' => $file->size_bytes,
                        'storage_path' => $file->storage_path,
                        'idempotency_key' => $request->header('X-Idempotency-Key'),
                    ],
                    request: $request,
                );

                return $file->fresh(['folder']);
            });
        } catch (\Throwable $exception) {
            if ($createdPath !== null && Storage::disk($disk)->exists($createdPath)) {
                Storage::disk($disk)->delete($createdPath);
            }
            if (Storage::disk($disk)->exists($tmpPath)) {
                Storage::disk($disk)->delete($tmpPath);
            }
            throw $exception;
        }

        if (Storage::disk($disk)->exists($tmpPath)) {
            Storage::disk($disk)->delete($tmpPath);
        }

        ComputeChecksumJob::dispatch($file->id);
        if ((bool) config('antivirus.enabled', true)) {
            VirusScanJob::dispatch($file->id);
        }

        return $file;
    }

    public function rename(User $actor, File $file, string $newName, Request $request): File
    {
        return DB::transaction(function () use ($actor, $file, $newName, $request): File {
            $record = File::query()->lockForUpdate()->findOrFail($file->id);

            $exists = File::query()
                ->where('folder_id', $record->folder_id)
                ->where('original_name', $newName)
                ->where('is_deleted', false)
                ->where('id', '!=', $record->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'original_name' => 'Another file with this name already exists.',
                ]);
            }

            $record->update(['original_name' => $newName]);

            $this->auditLogService->log(
                actor: $actor,
                action: 'file.renamed',
                entityType: 'file',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'file_public_id' => $record->public_id,
                    'new_name' => $newName,
                ],
                request: $request,
            );

            return $record;
        });
    }

    public function move(User $actor, File $file, Folder $destination, Request $request): File
    {
        return DB::transaction(function () use ($actor, $file, $destination, $request): File {
            $record = File::query()->lockForUpdate()->findOrFail($file->id);
            $target = Folder::query()->lockForUpdate()->findOrFail($destination->id);

            if ($target->is_deleted) {
                throw ValidationException::withMessages([
                    'destination_folder_id' => 'Cannot move a file into a deleted folder.',
                ]);
            }

            $exists = File::query()
                ->where('folder_id', $target->id)
                ->where('original_name', $record->original_name)
                ->where('is_deleted', false)
                ->where('id', '!=', $record->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'destination_folder_id' => 'A file with the same name already exists in the destination folder.',
                ]);
            }

            $record->update([
                'folder_id' => $target->id,
                'department_id' => $target->department_id,
                'visibility' => $target->visibility,
            ]);

            $this->auditLogService->log(
                actor: $actor,
                action: 'file.moved',
                entityType: 'file',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'file_public_id' => $record->public_id,
                    'destination_folder_public_id' => $target->public_id,
                ],
                request: $request,
            );

            return $record->fresh(['folder']);
        });
    }

    public function updateVisibility(User $actor, File $file, string $visibility, Request $request): File
    {
        return DB::transaction(function () use ($actor, $file, $visibility, $request): File {
            $record = File::query()->lockForUpdate()->findOrFail($file->id);
            $record->update([
                'visibility' => $visibility,
            ]);

            $this->auditLogService->log(
                actor: $actor,
                action: 'file.visibility_changed',
                entityType: 'file',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'file_public_id' => $record->public_id,
                    'visibility' => $visibility,
                ],
                request: $request,
            );

            return $record->fresh(['folder']);
        });
    }

    /**
     * @param  list<int>  $tagIds
     */
    public function syncTags(User $actor, File $file, array $tagIds, Request $request): File
    {
        return DB::transaction(function () use ($actor, $file, $tagIds, $request): File {
            $record = File::query()->lockForUpdate()->findOrFail($file->id);

            $validTagIds = Tag::query()
                ->whereIn('id', $tagIds)
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->all();

            $record->tags()->sync($validTagIds);

            $this->auditLogService->log(
                actor: $actor,
                action: 'file.tags_updated',
                entityType: 'file',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'file_public_id' => $record->public_id,
                    'tag_ids' => $validTagIds,
                ],
                request: $request,
            );

            return $record->fresh(['tags']);
        });
    }

    /**
     * @return Collection<int, FileVersion>
     */
    public function versions(File $file): Collection
    {
        return FileVersion::query()
            ->where('file_id', $file->id)
            ->with('creator:id,public_id,email')
            ->orderByDesc('version_no')
            ->get();
    }

    /**
     * @param  SupportCollection<int, File>  $files
     */
    public function downloadAsArchive(User $actor, SupportCollection $files, Request $request): BinaryFileResponse
    {
        $activeFiles = $files
            ->filter(static fn (File $file): bool => ! $file->is_deleted)
            ->values();

        if ($activeFiles->isEmpty()) {
            throw ValidationException::withMessages([
                'files' => 'No files are available for download.',
            ]);
        }

        $tempZipPath = $this->createTemporaryZipPath();
        $zip = new ZipArchive;
        $result = $zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new RuntimeException('Unable to create a file archive for download.');
        }

        $stagedPaths = [];
        $usedEntryPaths = [];

        try {
            foreach ($activeFiles as $file) {
                $entryName = $this->sanitizeArchiveSegment((string) $file->original_name, 'file');
                $entryPath = $this->ensureUniqueArchiveEntryPath($entryName, $usedEntryPaths);

                $disk = $this->storageDiskResolver->resolve($file->storage_disk);
                $storage = Storage::disk($disk);

                if (! $storage->exists($file->storage_path)) {
                    abort(404, 'One or more selected files are not available for download.');
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

                $stagedPath = tempnam(sys_get_temp_dir(), 'fm-selection-entry-');
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
            action: 'selection.downloaded',
            entityType: 'selection',
            entityId: null,
            meta: [
                'request_id' => $request->attributes->get('request_id'),
                'file_count' => $activeFiles->count(),
                'file_public_ids' => $activeFiles
                    ->pluck('public_id')
                    ->map(static fn ($id): string => (string) $id)
                    ->all(),
            ],
            request: $request,
        );

        $downloadName = 'selected-files-'.now()->format('Ymd-His').'.zip';

        return response()
            ->download($tempZipPath, $downloadName, [
                'Content-Type' => 'application/zip',
            ])
            ->deleteFileAfterSend(true);
    }

    public function restoreVersion(User $actor, File $file, int $versionNo, Request $request): File
    {
        return DB::transaction(function () use ($actor, $file, $versionNo, $request): File {
            $record = File::query()->lockForUpdate()->findOrFail($file->id);
            $version = FileVersion::query()
                ->where('file_id', $record->id)
                ->where('version_no', $versionNo)
                ->lockForUpdate()
                ->first();

            if (! $version) {
                throw ValidationException::withMessages([
                    'version' => 'Requested file version does not exist.',
                ]);
            }

            $nextVersion = (int) FileVersion::query()
                ->where('file_id', $record->id)
                ->max('version_no') + 1;

            FileVersion::query()->create([
                'file_id' => $record->id,
                'version_no' => $nextVersion,
                'stored_name' => $record->stored_name,
                'storage_path' => $record->storage_path,
                'size_bytes' => $record->size_bytes,
                'checksum_sha256' => $record->checksum_sha256,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);

            $record->update([
                'stored_name' => $version->stored_name,
                'storage_path' => $version->storage_path,
                'size_bytes' => $version->size_bytes,
                'checksum_sha256' => $version->checksum_sha256,
            ]);

            $this->auditLogService->log(
                actor: $actor,
                action: 'file.version_restored',
                entityType: 'file',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'file_public_id' => $record->public_id,
                    'restored_version_no' => $versionNo,
                    'backup_version_no' => $nextVersion,
                ],
                request: $request,
            );

            return $record->fresh();
        });
    }

    public function softDelete(User $actor, File $file, Request $request): File
    {
        return DB::transaction(function () use ($actor, $file, $request): File {
            $record = File::query()->lockForUpdate()->findOrFail($file->id);
            $record->update([
                'is_deleted' => true,
                'deleted_at' => now(),
            ]);

            $this->auditLogService->log(
                actor: $actor,
                action: 'file.deleted',
                entityType: 'file',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'file_public_id' => $record->public_id,
                    'idempotency_key' => $request->header('X-Idempotency-Key'),
                ],
                request: $request,
            );

            return $record;
        });
    }

    public function permanentlyDelete(User $actor, File $file, Request $request): void
    {
        $pathsByDisk = [];

        DB::transaction(function () use ($actor, $file, $request, &$pathsByDisk): void {
            $record = File::query()->lockForUpdate()->findOrFail($file->id);

            if (! $record->is_deleted) {
                throw ValidationException::withMessages([
                    'file' => 'File must be in trash before deleting forever.',
                ]);
            }

            $disk = $this->storageDiskResolver->resolve($record->storage_disk);
            $pathsByDisk[$disk][] = $record->storage_path;

            $versionPaths = FileVersion::query()
                ->where('file_id', $record->id)
                ->pluck('storage_path')
                ->all();

            foreach ($versionPaths as $versionPath) {
                $pathsByDisk[$disk][] = $versionPath;
            }

            $record->delete();

            $this->auditLogService->log(
                actor: $actor,
                action: 'file.purged',
                entityType: 'file',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'file_public_id' => $record->public_id,
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

    public function restore(User $actor, File $file, Request $request): File
    {
        return DB::transaction(function () use ($actor, $file, $request): File {
            $record = File::query()->lockForUpdate()->findOrFail($file->id);
            $this->restoreParentFoldersForFile($record);

            $exists = File::query()
                ->where('folder_id', $record->folder_id)
                ->where('original_name', $record->original_name)
                ->where('is_deleted', false)
                ->where('id', '!=', $record->id)
                ->exists();

            if ($exists) {
                $record->original_name = $this->autoRename($record->folder_id, $record->original_name, $record->id);
            }

            $record->is_deleted = false;
            $record->deleted_at = null;
            $record->save();

            $this->auditLogService->log(
                actor: $actor,
                action: 'file.restored',
                entityType: 'file',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'file_public_id' => $record->public_id,
                    'idempotency_key' => $request->header('X-Idempotency-Key'),
                ],
                request: $request,
            );

            return $record;
        });
    }

    private function restoreParentFoldersForFile(File $record): void
    {
        $chain = [];
        $current = Folder::query()
            ->lockForUpdate()
            ->find($record->folder_id);

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

            $hasConflict = Folder::query()
                ->where('parent_id', $folder->parent_id)
                ->where('name', $folder->name)
                ->where('owner_user_id', $folder->owner_user_id)
                ->where('department_id', $folder->department_id)
                ->where('is_deleted', false)
                ->where('id', '!=', $folder->id)
                ->exists();

            if ($hasConflict) {
                throw ValidationException::withMessages([
                    'file' => 'Cannot restore file because a parent folder name conflict exists.',
                ]);
            }

            $folder->is_deleted = false;
            $folder->deleted_at = null;
            $folder->save();
        }
    }

    private function buildFinalPath(Folder $folder, string $storedName): string
    {
        $monthPath = now()->format('Y/m');

        if ($folder->owner_user_id !== null) {
            $ownerPublicId = $folder->owner?->public_id ?? 'unknown-owner';

            return "private/{$ownerPublicId}/{$folder->public_id}/{$monthPath}/{$storedName}";
        }

        $departmentCode = $folder->department?->code ?? 'unknown-department';

        return "department/{$departmentCode}/{$folder->public_id}/{$monthPath}/{$storedName}";
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
        $tempPath = tempnam(sys_get_temp_dir(), 'fm-selection-');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to allocate temporary storage for the file archive.');
        }

        @unlink($tempPath);

        return $tempPath.'.zip';
    }

    private function autoRename(int $folderId, string $originalName, ?int $excludeId = null): string
    {
        $dot = strrpos($originalName, '.');
        $base = $dot === false ? $originalName : substr($originalName, 0, $dot);
        $ext = $dot === false ? '' : substr($originalName, $dot);

        $counter = 1;
        do {
            $candidate = "{$base} ({$counter}){$ext}";
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
