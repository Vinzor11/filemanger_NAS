<?php

namespace App\Http\Controllers\Explorer;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\Folder;
use App\Services\FileService;
use App\Services\FolderService;
use App\Services\SharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SelectionController extends Controller
{
    public function __construct(
        private readonly FileService $fileService,
        private readonly FolderService $folderService,
        private readonly SharingService $sharingService,
    ) {
    }

    public function trash(Request $request): RedirectResponse
    {
        [$files, $folders] = $this->resolveSelection($request);

        foreach ($folders as $folder) {
            $this->authorize('delete', $folder);
        }

        foreach ($files as $file) {
            $this->authorize('delete', $file);
        }

        foreach ($folders as $folder) {
            $this->folderService->softDelete($request->user(), $folder, $request);
        }

        foreach ($files as $file) {
            $this->fileService->softDelete($request->user(), $file, $request);
        }

        if ($request->boolean('silent')) {
            return back();
        }

        $total = $files->count() + $folders->count();

        return back()->with('status', "{$total} item(s) moved to trash.");
    }

    public function move(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['uuid', 'distinct', 'exists:files,public_id'],
            'destination_folder_id' => ['required', 'uuid', 'exists:folders,public_id'],
            'silent' => ['sometimes', 'boolean'],
        ]);

        $filePublicIds = collect($validated['files'])
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
        $files = $this->resolveFilesByPublicIds($filePublicIds);

        $destination = Folder::query()
            ->where('public_id', $validated['destination_folder_id'])
            ->firstOrFail();

        $this->authorize('upload', $destination);

        foreach ($files as $file) {
            $this->authorize('update', $file);
        }

        foreach ($files as $file) {
            $this->fileService->move($request->user(), $file, $destination, $request);
        }

        if ($request->boolean('silent')) {
            return back();
        }

        return back()->with('status', "{$files->count()} file(s) moved.");
    }

    public function download(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['uuid', 'distinct', 'exists:files,public_id'],
        ]);

        $filePublicIds = collect($validated['files'])
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
        $files = $this->resolveFilesByPublicIds($filePublicIds);

        foreach ($files as $file) {
            $this->authorize('download', $file);
        }

        return $this->fileService->downloadAsArchive($request->user(), $files, $request);
    }

    public function availableEmployees(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->sharingService->availableEmployees(
                actor: $request->user(),
                search: $request->string('q')->toString(),
                limit: $request->integer('limit', 100),
            ),
        ]);
    }

    public function shareUsers(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['uuid', 'distinct', 'exists:files,public_id'],
            'shares' => ['required', 'array', 'min:1'],
            'shares.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'shares.*.can_view' => ['sometimes', 'boolean'],
            'shares.*.can_download' => ['sometimes', 'boolean'],
            'shares.*.can_edit' => ['sometimes', 'boolean'],
            'shares.*.can_delete' => ['sometimes', 'boolean'],
            'silent' => ['sometimes', 'boolean'],
        ]);

        $filePublicIds = collect($validated['files'])
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
        $files = $this->resolveFilesByPublicIds($filePublicIds);

        foreach ($files as $file) {
            $this->authorize('share', $file);
        }

        foreach ($files as $file) {
            $this->sharingService->upsertUserShares(
                actor: $request->user(),
                file: $file,
                shares: $validated['shares'],
                request: $request,
            );
        }

        if ($request->boolean('silent')) {
            return back();
        }

        return back()->with('status', "Sharing updated for {$files->count()} file(s).");
    }

    public function restore(Request $request): RedirectResponse
    {
        [$files, $folders] = $this->resolveSelection($request);

        foreach ($folders as $folder) {
            $this->authorize('restore', $folder);
        }

        foreach ($files as $file) {
            $this->authorize('restore', $file);
        }

        // Restore folder trees first, then any directly selected files.
        foreach ($folders as $folder) {
            $this->folderService->restore($request->user(), $folder, $request);
        }

        foreach ($files as $file) {
            $this->fileService->restore($request->user(), $file, $request);
        }

        if ($request->boolean('silent')) {
            return back();
        }

        $total = $files->count() + $folders->count();

        return back()->with('status', "{$total} item(s) restored.");
    }

    public function purge(Request $request): RedirectResponse
    {
        [$files, $folders] = $this->resolveSelection($request);

        foreach ($folders as $folder) {
            $this->authorize('delete', $folder);
        }

        foreach ($files as $file) {
            $this->authorize('delete', $file);
        }

        // Purge direct files first to avoid duplicate work if a parent folder is also selected.
        foreach ($files as $file) {
            $this->fileService->permanentlyDelete($request->user(), $file, $request);
        }

        foreach ($folders as $folder) {
            $this->folderService->permanentlyDelete($request->user(), $folder, $request);
        }

        if ($request->boolean('silent')) {
            return back();
        }

        $total = $files->count() + $folders->count();

        return back()->with('status', "{$total} item(s) deleted forever.");
    }

    /**
     * @return Collection<int, File>
     */
    private function resolveFilesByPublicIds(array $filePublicIds): Collection
    {
        $filesByPublicId = File::query()
            ->whereIn('public_id', $filePublicIds)
            ->get()
            ->keyBy('public_id');

        return collect($filePublicIds)
            ->map(fn (string $publicId): ?File => $filesByPublicId->get($publicId))
            ->filter()
            ->values();
    }

    /**
     * @return array{0: Collection<int, File>, 1: Collection<int, Folder>}
     */
    private function resolveSelection(Request $request): array
    {
        $validated = $request->validate([
            'files' => ['sometimes', 'array'],
            'files.*' => ['uuid', 'distinct', 'exists:files,public_id'],
            'folders' => ['sometimes', 'array'],
            'folders.*' => ['uuid', 'distinct', 'exists:folders,public_id'],
            'silent' => ['sometimes', 'boolean'],
        ]);

        $filePublicIds = collect($validated['files'] ?? [])
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
        $folderPublicIds = collect($validated['folders'] ?? [])
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();

        $files = $this->resolveFilesByPublicIds($filePublicIds);

        $foldersByPublicId = Folder::query()
            ->whereIn('public_id', $folderPublicIds)
            ->get()
            ->keyBy('public_id');
        $folders = collect($folderPublicIds)
            ->map(fn (string $publicId): ?Folder => $foldersByPublicId->get($publicId))
            ->filter()
            ->values();

        return [$files, $folders];
    }
}
