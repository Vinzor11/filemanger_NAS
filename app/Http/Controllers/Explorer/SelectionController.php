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
use Illuminate\Validation\ValidationException;
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
        $request->validate([
            'files' => ['sometimes', 'array'],
            'files.*' => ['uuid', 'distinct', 'exists:files,public_id'],
            'folders' => ['sometimes', 'array'],
            'folders.*' => ['uuid', 'distinct', 'exists:folders,public_id'],
            'destination_folder_id' => ['required', 'uuid', 'exists:folders,public_id'],
            'silent' => ['sometimes', 'boolean'],
        ]);

        [$files, $folders] = $this->resolveSelection($request);
        $this->ensureSelectionNotEmpty($files, $folders);
        $foldersToMove = $this->rootSelectionFolders($folders);
        $selectedFolderIdLookup = $this->collectSubtreeFolderIdLookup($foldersToMove);
        $filesToMove = $this->excludeFilesInFolderLookup($files, $selectedFolderIdLookup);

        $destination = Folder::query()
            ->where('public_id', (string) $request->input('destination_folder_id'))
            ->firstOrFail();

        $this->authorize('upload', $destination);

        foreach ($foldersToMove as $folder) {
            $this->authorize('update', $folder);
        }

        foreach ($filesToMove as $file) {
            $this->authorize('update', $file);
        }

        foreach ($foldersToMove as $folder) {
            $this->folderService->move($request->user(), $folder, $destination, $request);
        }

        foreach ($filesToMove as $file) {
            $this->fileService->move($request->user(), $file, $destination, $request);
        }

        if ($request->boolean('silent')) {
            return back();
        }

        $total = $filesToMove->count() + $foldersToMove->count();

        return back()->with('status', "{$total} item(s) moved.");
    }

    public function download(Request $request): BinaryFileResponse
    {
        $request->validate([
            'files' => ['sometimes', 'array'],
            'files.*' => ['uuid', 'distinct', 'exists:files,public_id'],
            'folders' => ['sometimes', 'array'],
            'folders.*' => ['uuid', 'distinct', 'exists:folders,public_id'],
        ]);

        [$files, $folders] = $this->resolveSelection($request);
        $this->ensureSelectionNotEmpty($files, $folders);
        $foldersToDownload = $this->rootSelectionFolders($folders);
        $selectedFolderIdLookup = $this->collectSubtreeFolderIdLookup($foldersToDownload);

        $filesInSelectedFolders = collect();
        if ($selectedFolderIdLookup !== []) {
            $filesInSelectedFolders = File::query()
                ->whereIn('folder_id', array_keys($selectedFolderIdLookup))
                ->where('is_deleted', false)
                ->get();
        }

        $downloadFiles = $files
            ->concat($filesInSelectedFolders)
            ->unique('id')
            ->values();

        foreach ($downloadFiles as $file) {
            $this->authorize('download', $file);
        }

        return $this->fileService->downloadAsArchive($request->user(), $downloadFiles, $request);
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
            'files' => ['sometimes', 'array'],
            'files.*' => ['uuid', 'distinct', 'exists:files,public_id'],
            'folders' => ['sometimes', 'array'],
            'folders.*' => ['uuid', 'distinct', 'exists:folders,public_id'],
            'shares' => ['required', 'array', 'min:1'],
            'shares.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'shares.*.can_view' => ['sometimes', 'boolean'],
            'shares.*.can_download' => ['sometimes', 'boolean'],
            'shares.*.can_upload' => ['sometimes', 'boolean'],
            'shares.*.can_edit' => ['sometimes', 'boolean'],
            'shares.*.can_delete' => ['sometimes', 'boolean'],
            'silent' => ['sometimes', 'boolean'],
        ]);

        [$files, $folders] = $this->resolveSelection($request);
        $this->ensureSelectionNotEmpty($files, $folders);
        $foldersToShare = $this->rootSelectionFolders($folders);
        $selectedFolderIdLookup = $this->collectSubtreeFolderIdLookup($foldersToShare);
        $filesToShare = $this->excludeFilesInFolderLookup($files, $selectedFolderIdLookup);

        foreach ($foldersToShare as $folder) {
            $this->authorize('share', $folder);
        }

        foreach ($filesToShare as $file) {
            $this->authorize('share', $file);
        }

        foreach ($filesToShare as $file) {
            $this->sharingService->upsertUserShares(
                actor: $request->user(),
                file: $file,
                shares: $validated['shares'],
                request: $request,
            );
        }

        foreach ($foldersToShare as $folder) {
            $this->sharingService->upsertFolderShares(
                actor: $request->user(),
                folder: $folder,
                shares: $validated['shares'],
                request: $request,
            );
        }

        if ($request->boolean('silent')) {
            return back();
        }

        $total = $filesToShare->count() + $foldersToShare->count();

        return back()->with('status', "Sharing updated for {$total} item(s).");
    }

    public function shareDepartment(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'files' => ['sometimes', 'array'],
            'files.*' => ['uuid', 'distinct', 'exists:files,public_id'],
            'folders' => ['sometimes', 'array'],
            'folders.*' => ['uuid', 'distinct', 'exists:folders,public_id'],
            'can_view' => ['sometimes', 'boolean'],
            'can_download' => ['sometimes', 'boolean'],
            'can_upload' => ['sometimes', 'boolean'],
            'can_edit' => ['sometimes', 'boolean'],
            'can_delete' => ['sometimes', 'boolean'],
            'silent' => ['sometimes', 'boolean'],
        ]);

        [$files, $folders] = $this->resolveSelection($request);
        $this->ensureSelectionNotEmpty($files, $folders);
        $foldersToShare = $this->rootSelectionFolders($folders);
        $selectedFolderIdLookup = $this->collectSubtreeFolderIdLookup($foldersToShare);
        $filesToShare = $this->excludeFilesInFolderLookup($files, $selectedFolderIdLookup);

        foreach ($foldersToShare as $folder) {
            $this->authorize('share', $folder);
        }

        foreach ($filesToShare as $file) {
            $this->authorize('share', $file);
        }

        $permissions = collect($validated)
            ->only(['can_view', 'can_download', 'can_upload', 'can_edit', 'can_delete'])
            ->all();

        foreach ($filesToShare as $file) {
            $this->sharingService->shareToDepartment(
                actor: $request->user(),
                file: $file,
                permissions: $permissions,
                request: $request,
            );
        }

        foreach ($foldersToShare as $folder) {
            $this->sharingService->shareFolderToDepartment(
                actor: $request->user(),
                folder: $folder,
                permissions: $permissions,
                request: $request,
            );
        }

        if ($request->boolean('silent')) {
            return back();
        }

        $total = $filesToShare->count() + $foldersToShare->count();

        return back()->with('status', "Department sharing updated for {$total} item(s).");
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

    private function ensureSelectionNotEmpty(Collection $files, Collection $folders): void
    {
        if ($files->isNotEmpty() || $folders->isNotEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'selection' => 'Select at least one file or folder.',
        ]);
    }

    /**
     * @param  Collection<int, Folder>  $folders
     * @return Collection<int, Folder>
     */
    private function rootSelectionFolders(Collection $folders): Collection
    {
        if ($folders->isEmpty()) {
            return $folders;
        }

        $sorted = $folders
            ->sortBy(
                static fn (Folder $folder): int => strlen(trim((string) $folder->path, '/')),
            )
            ->values();

        $rootFolders = collect();
        $rootPaths = [];

        foreach ($sorted as $folder) {
            $path = trim((string) $folder->path, '/');
            if ($path === '') {
                $rootFolders->push($folder);
                continue;
            }

            $isNestedSelection = false;
            foreach ($rootPaths as $rootPath) {
                if ($path === $rootPath || str_starts_with($path, "{$rootPath}/")) {
                    $isNestedSelection = true;
                    break;
                }
            }

            if ($isNestedSelection) {
                continue;
            }

            $rootFolders->push($folder);
            $rootPaths[] = $path;
        }

        return $rootFolders->values();
    }

    /**
     * @param  Collection<int, Folder>  $folders
     * @return array<int, bool>
     */
    private function collectSubtreeFolderIdLookup(Collection $folders): array
    {
        $rootIds = $folders
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($rootIds === []) {
            return [];
        }

        $lookup = array_fill_keys($rootIds, true);
        $cursor = $rootIds;

        while ($cursor !== []) {
            $next = Folder::query()
                ->whereIn('parent_id', $cursor)
                ->where('is_deleted', false)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $next = array_values(array_filter(
                $next,
                static fn (int $id): bool => ! isset($lookup[$id]),
            ));

            if ($next === []) {
                break;
            }

            foreach ($next as $id) {
                $lookup[$id] = true;
            }

            $cursor = $next;
        }

        return $lookup;
    }

    /**
     * @param  Collection<int, File>  $files
     * @param  array<int, bool>  $folderIdLookup
     * @return Collection<int, File>
     */
    private function excludeFilesInFolderLookup(Collection $files, array $folderIdLookup): Collection
    {
        if ($files->isEmpty() || $folderIdLookup === []) {
            return $files->values();
        }

        return $files
            ->filter(
                static fn (File $file): bool => ! isset($folderIdLookup[(int) $file->folder_id]),
            )
            ->values();
    }
}
