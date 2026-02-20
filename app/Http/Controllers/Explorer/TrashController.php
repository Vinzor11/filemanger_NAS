<?php

namespace App\Http\Controllers\Explorer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Explorer\ExplorerFilterRequest;
use App\Models\Folder;
use App\Services\ExplorerService;
use Inertia\Inertia;
use Inertia\Response;

class TrashController extends Controller
{
    public function __construct(
        private readonly ExplorerService $explorerService,
    ) {
    }

    public function index(ExplorerFilterRequest $request): Response
    {
        $data = $this->explorerService->trash($request->user(), $request->validated());

        return Inertia::render('explorer/trash', [
            'folders' => $data['folders'],
            'files' => $data['files'],
            'filters' => $request->validated(),
        ]);
    }

    public function showFolder(Folder $folder, ExplorerFilterRequest $request): Response
    {
        $data = $this->explorerService->trashFolderContents(
            $request->user(),
            $folder,
            $request->validated(),
        );

        return Inertia::render('explorer/trash-folder', [
            'folder' => $data['folder'],
            'breadcrumbTrail' => $data['breadcrumbTrail'],
            'children' => $data['children'],
            'files' => $data['files'],
            'filters' => $request->validated(),
        ]);
    }
}
