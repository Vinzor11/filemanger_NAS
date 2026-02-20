<?php

namespace App\Http\Controllers\Explorer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Explorer\ExplorerFilterRequest;
use App\Services\ExplorerService;
use Inertia\Inertia;
use Inertia\Response;

class ExplorerController extends Controller
{
    public function __construct(
        private readonly ExplorerService $explorerService,
    ) {}

    public function myFiles(ExplorerFilterRequest $request): Response
    {
        $data = $this->explorerService->myFiles($request->user(), $request->validated());

        return Inertia::render('explorer/my-files', [
            'folders' => $data['folders'],
            'files' => $data['files'],
            'filters' => $request->validated(),
        ]);
    }

    public function departmentFiles(ExplorerFilterRequest $request): Response
    {
        $data = $this->explorerService->departmentFiles($request->user(), $request->validated());

        return Inertia::render('explorer/department-files', [
            'folders' => $data['folders'],
            'files' => $data['files'],
            'filters' => $request->validated(),
        ]);
    }

    public function sharedWithMe(ExplorerFilterRequest $request): Response
    {
        $data = $this->explorerService->sharedWithMe($request->user(), $request->validated());

        return Inertia::render('explorer/shared-with-me', [
            'folders' => $data['folders'],
            'files' => $data['files'],
            'filters' => $request->validated(),
        ]);
    }
}
