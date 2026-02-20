<?php

namespace App\Http\Controllers\Explorer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Explorer\ExplorerFilterRequest;
use App\Http\Requests\Explorer\FolderMoveRequest;
use App\Http\Requests\Explorer\FolderStoreRequest;
use App\Models\AuditLog;
use App\Models\Folder;
use App\Services\ExplorerService;
use App\Services\FolderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FolderController extends Controller
{
    public function __construct(
        private readonly FolderService $folderService,
        private readonly ExplorerService $explorerService,
    ) {
    }

    public function show(Folder $folder, ExplorerFilterRequest $request): Response
    {
        $this->authorize('view', $folder);

        $data = $this->explorerService->folderContents($request->user(), $folder, $request->validated());
        $breadcrumbTrail = $this->folderService->breadcrumbTrail($folder);

        return Inertia::render('explorer/folder-show', [
            'folder' => $folder->loadMissing(['parent', 'department', 'owner']),
            'abilities' => [
                'can_upload' => $request->user()->can('upload', $folder),
                'can_create_folder' => $request->user()->can('create', [Folder::class, $folder]),
            ],
            'children' => $data['children'],
            'files' => $data['files'],
            'breadcrumbTrail' => $breadcrumbTrail,
            'filters' => $request->validated(),
        ]);
    }

    public function download(Request $request, Folder $folder): BinaryFileResponse
    {
        $this->authorize('view', $folder);

        return $this->folderService->downloadArchive($request->user(), $folder, $request);
    }

    public function activities(Request $request, Folder $folder): JsonResponse
    {
        $this->authorize('view', $folder);

        $activities = AuditLog::query()
            ->where('entity_type', 'folder')
            ->where('entity_id', $folder->id)
            ->with('actor:id,public_id,email')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $activities,
        ]);
    }

    public function store(FolderStoreRequest $request): RedirectResponse
    {
        $parent = null;
        if ($request->filled('parent_id')) {
            $parent = Folder::query()->where('public_id', $request->string('parent_id'))->firstOrFail();
        }
        $this->authorize('create', [Folder::class, $parent]);

        $folder = $this->folderService->create($request->user(), $request->validated(), $request);

        return redirect()->route('folders.show', ['folder' => $folder->public_id])->with('status', 'Folder created.');
    }

    public function update(Request $request, Folder $folder): RedirectResponse
    {
        $this->authorize('update', $folder);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'not_regex:/[\/\\\\]/'],
        ]);

        $this->folderService->rename($request->user(), $folder, trim((string) $validated['name']), $request);

        return back()->with('status', 'Folder renamed.');
    }

    public function move(FolderMoveRequest $request, Folder $folder): RedirectResponse
    {
        $this->authorize('update', $folder);

        $destination = Folder::query()
            ->where('public_id', $request->validated('destination_folder_id'))
            ->firstOrFail();
        $this->authorize('update', $destination);

        $this->folderService->move($request->user(), $folder, $destination, $request);

        return back()->with('status', 'Folder moved.');
    }

    public function destroy(Request $request, Folder $folder): RedirectResponse
    {
        $this->authorize('delete', $folder);
        $this->folderService->softDelete($request->user(), $folder, $request);

        if ($request->boolean('silent')) {
            return back();
        }

        return back()->with('status', 'Folder moved to trash.');
    }

    public function purge(Request $request, Folder $folder): RedirectResponse
    {
        $this->authorize('delete', $folder);
        $this->folderService->permanentlyDelete($request->user(), $folder, $request);

        if ($request->boolean('silent')) {
            return back();
        }

        return back()->with('status', 'Folder deleted forever.');
    }

    public function restore(Request $request, Folder $folder): RedirectResponse
    {
        $this->authorize('restore', $folder);
        $restored = $this->folderService->restore($request->user(), $folder, $request);
        $statusMessage = "Folder restored: {$restored->name}.";

        if ($request->boolean('silent')) {
            return back();
        }

        $refererPath = parse_url((string) $request->headers->get('referer'), PHP_URL_PATH);
        if (is_string($refererPath) && str_starts_with($refererPath, '/trash/folders/')) {
            return redirect()
                ->route('folders.show', ['folder' => $restored->public_id])
                ->with('status', $statusMessage);
        }

        return back()->with('status', $statusMessage);
    }
}
