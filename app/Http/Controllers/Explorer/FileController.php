<?php

namespace App\Http\Controllers\Explorer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Explorer\FileMoveRequest;
use App\Http\Requests\Explorer\FileRenameRequest;
use App\Http\Requests\Explorer\FileTagSyncRequest;
use App\Http\Requests\Explorer\FileUploadRequest;
use App\Http\Requests\Explorer\FileVisibilityUpdateRequest;
use App\Models\AuditLog;
use App\Models\File;
use App\Models\Folder;
use App\Services\AuditLogService;
use App\Services\FileService;
use App\Services\StorageDiskResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function __construct(
        private readonly FileService $fileService,
        private readonly AuditLogService $auditLogService,
        private readonly StorageDiskResolver $storageDiskResolver,
    ) {
    }

    public function upload(FileUploadRequest $request): RedirectResponse
    {
        $folder = Folder::query()->where('public_id', $request->validated('folder_id'))->firstOrFail();
        $this->authorize('upload', $folder);

        $this->fileService->uploadToFolder(
            actor: $request->user(),
            folder: $folder,
            uploadedFile: $request->file('file'),
            options: [
                'duplicate_mode' => $request->validated('duplicate_mode', 'fail'),
                'original_name' => $request->validated('original_name'),
            ],
            request: $request,
        );

        return back()->with('status', 'File uploaded.');
    }

    public function replace(Request $request, File $file): RedirectResponse
    {
        $this->authorize('update', $file);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:51200'],
        ]);

        $this->fileService->uploadToFolder(
            actor: $request->user(),
            folder: $file->folder,
            uploadedFile: $validated['file'],
            options: [
                'duplicate_mode' => 'replace',
                'original_name' => $file->original_name,
                'storage_disk' => $file->storage_disk,
            ],
            request: $request,
        );

        return back()->with('status', 'File replaced.');
    }

    public function rename(FileRenameRequest $request, File $file): RedirectResponse
    {
        $this->authorize('update', $file);
        $this->fileService->rename($request->user(), $file, $request->validated('original_name'), $request);

        return back()->with('status', 'File renamed.');
    }

    public function move(FileMoveRequest $request, File $file): RedirectResponse
    {
        $this->authorize('update', $file);

        $destination = Folder::query()
            ->where('public_id', $request->validated('destination_folder_id'))
            ->firstOrFail();
        $this->authorize('upload', $destination);

        $this->fileService->move($request->user(), $file, $destination, $request);

        return back()->with('status', 'File moved.');
    }

    public function updateVisibility(FileVisibilityUpdateRequest $request, File $file): RedirectResponse
    {
        $this->authorize('update', $file);

        $this->fileService->updateVisibility(
            actor: $request->user(),
            file: $file,
            visibility: $request->validated('visibility'),
            request: $request,
        );

        return back()->with('status', 'File visibility updated.');
    }

    public function syncTags(FileTagSyncRequest $request, File $file): RedirectResponse
    {
        $this->authorize('update', $file);

        $tagIds = collect($request->validated('tag_ids', []))
            ->map(static fn ($id) => (int) $id)
            ->all();

        $this->fileService->syncTags($request->user(), $file, $tagIds, $request);

        return back()->with('status', 'File tags updated.');
    }

    public function show(Request $request, File $file): JsonResponse
    {
        $this->authorize('view', $file);

        return response()->json([
            'data' => $file->loadMissing(['folder:id,public_id,name', 'owner:id,public_id,email', 'department:id,name,code', 'tags:id,name']),
        ]);
    }

    public function versions(Request $request, File $file): JsonResponse
    {
        $this->authorize('view', $file);

        return response()->json([
            'data' => $this->fileService->versions($file),
        ]);
    }

    public function activities(Request $request, File $file): JsonResponse
    {
        $this->authorize('view', $file);

        $activities = AuditLog::query()
            ->where('entity_type', 'file')
            ->where('entity_id', $file->id)
            ->with('actor:id,public_id,email')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $activities,
        ]);
    }

    public function restoreVersion(Request $request, File $file, int $versionNo): RedirectResponse
    {
        $this->authorize('update', $file);

        $this->fileService->restoreVersion($request->user(), $file, $versionNo, $request);

        return back()->with('status', 'File version restored.');
    }

    public function download(Request $request, File $file): StreamedResponse
    {
        $this->authorize('download', $file);

        $disk = $this->storageDiskResolver->resolve($file->storage_disk);
        abort_unless(Storage::disk($disk)->exists($file->storage_path), 404, 'File binary not found.');

        $this->auditLogService->log(
            actor: $request->user(),
            action: 'file.downloaded',
            entityType: 'file',
            entityId: $file->id,
            meta: [
                'request_id' => $request->attributes->get('request_id'),
                'file_public_id' => $file->public_id,
            ],
            request: $request,
        );

        return Storage::disk($disk)->download($file->storage_path, $file->original_name);
    }

    public function preview(Request $request, File $file): StreamedResponse
    {
        $this->authorize('view', $file);
        abort_if($file->is_deleted, 404, 'File is not available for preview.');

        $disk = $this->storageDiskResolver->resolve($file->storage_disk);
        abort_unless(Storage::disk($disk)->exists($file->storage_path), 404, 'File binary not found.');

        $mimeType = $file->mime_type ?: (Storage::disk($disk)->mimeType($file->storage_path) ?: 'application/octet-stream');

        return Storage::disk($disk)->response(
            $file->storage_path,
            $file->original_name,
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="'.addslashes($file->original_name).'"',
                'Cache-Control' => 'private, max-age=120',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function destroy(Request $request, File $file): RedirectResponse
    {
        $this->authorize('delete', $file);
        $this->fileService->softDelete($request->user(), $file, $request);

        if ($request->boolean('silent')) {
            return back();
        }

        return back()->with('status', 'File moved to trash.');
    }

    public function purge(Request $request, File $file): RedirectResponse
    {
        $this->authorize('delete', $file);
        $this->fileService->permanentlyDelete($request->user(), $file, $request);

        if ($request->boolean('silent')) {
            return back();
        }

        return back()->with('status', 'File deleted forever.');
    }

    public function restore(Request $request, File $file): RedirectResponse
    {
        $this->authorize('restore', $file);
        $restored = $this->fileService->restore($request->user(), $file, $request);
        $restored->loadMissing('folder:id,public_id,name');
        $folderName = $restored->folder?->name ?? 'its original folder';
        $statusMessage = "File restored to {$folderName}.";

        if ($request->boolean('silent')) {
            return back();
        }

        $refererPath = parse_url((string) $request->headers->get('referer'), PHP_URL_PATH);
        if (
            is_string($refererPath) &&
            str_starts_with($refererPath, '/trash/folders/') &&
            $restored->folder !== null
        ) {
            return redirect()
                ->route('folders.show', ['folder' => $restored->folder->public_id])
                ->with('status', $statusMessage);
        }

        return back()->with('status', $statusMessage);
    }
}
