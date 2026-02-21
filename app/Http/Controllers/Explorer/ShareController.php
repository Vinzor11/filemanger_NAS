<?php

namespace App\Http\Controllers\Explorer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Explorer\ShareDepartmentRequest;
use App\Http\Requests\Explorer\ShareFolderDepartmentRequest;
use App\Http\Requests\Explorer\ShareFolderUsersRequest;
use App\Http\Requests\Explorer\ShareLinkCreateRequest;
use App\Http\Requests\Explorer\ShareLinkUpdateRequest;
use App\Http\Requests\Explorer\ShareUserUpdateRequest;
use App\Http\Requests\Explorer\ShareUsersRequest;
use App\Models\File;
use App\Models\Folder;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\SharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShareController extends Controller
{
    public function __construct(
        private readonly SharingService $sharingService,
    ) {
    }

    public function shareUsers(ShareUsersRequest $request, File $file): RedirectResponse
    {
        $this->authorize('share', $file);

        $this->sharingService->upsertUserShares(
            actor: $request->user(),
            file: $file,
            shares: $request->validated('shares'),
            request: $request,
        );

        return back()->with('status', 'File sharing updated.');
    }

    public function availableEmployees(Request $request, File $file): JsonResponse
    {
        $this->authorize('share', $file);

        return response()->json([
            'data' => $this->sharingService->availableEmployees(
                actor: $request->user(),
                search: $request->string('q')->toString(),
                limit: $request->integer('limit', 100),
            ),
        ]);
    }

    public function availableEmployeesForFolder(Request $request, Folder $folder): JsonResponse
    {
        $this->authorize('share', $folder);

        return response()->json([
            'data' => $this->sharingService->availableEmployees(
                actor: $request->user(),
                search: $request->string('q')->toString(),
                limit: $request->integer('limit', 100),
            ),
        ]);
    }

    public function shareDepartment(ShareDepartmentRequest $request, File $file): RedirectResponse
    {
        $this->authorize('share', $file);

        $this->sharingService->shareToDepartment(
            actor: $request->user(),
            file: $file,
            permissions: $request->validated(),
            request: $request,
        );

        return back()->with('status', 'File shared with your department.');
    }

    public function shareFolderDepartment(ShareFolderDepartmentRequest $request, Folder $folder): RedirectResponse
    {
        $this->authorize('share', $folder);

        $this->sharingService->shareFolderToDepartment(
            actor: $request->user(),
            folder: $folder,
            permissions: $request->validated(),
            request: $request,
        );

        return back()->with('status', 'Folder shared with your department.');
    }

    public function shareFolderUsers(ShareFolderUsersRequest $request, Folder $folder): RedirectResponse
    {
        $this->authorize('share', $folder);

        $this->sharingService->upsertFolderShares(
            actor: $request->user(),
            folder: $folder,
            shares: $request->validated('shares'),
            request: $request,
        );

        return back()->with('status', 'Folder sharing updated.');
    }

    public function listFolderUserShares(Request $request, Folder $folder): JsonResponse
    {
        $this->authorize('share', $folder);

        return response()->json([
            'data' => $this->sharingService->listFolderUserShares($folder),
        ]);
    }

    public function listUserShares(Request $request, File $file): JsonResponse
    {
        $this->authorize('share', $file);

        return response()->json([
            'data' => $this->sharingService->listUserShares($file),
        ]);
    }

    public function updateUserShare(ShareUserUpdateRequest $request, File $file, User $targetUser): RedirectResponse
    {
        $this->authorize('share', $file);

        $this->sharingService->updateUserShare(
            actor: $request->user(),
            file: $file,
            targetUser: $targetUser,
            input: $request->validated(),
            request: $request,
        );

        return back()->with('status', 'User share updated.');
    }

    public function revokeUserShare(Request $request, File $file, User $targetUser): RedirectResponse
    {
        $this->authorize('share', $file);

        $this->sharingService->revokeUserShare($request->user(), $file, $targetUser, $request);

        return back()->with('status', 'User share revoked.');
    }

    public function revokeFolderUserShare(Request $request, Folder $folder, User $targetUser): RedirectResponse
    {
        $this->authorize('share', $folder);

        $this->sharingService->revokeFolderUserShare($request->user(), $folder, $targetUser, $request);

        return back()->with('status', 'User share revoked.');
    }

    public function revokeOwnFileShare(Request $request, File $file): RedirectResponse
    {
        $this->authorize('view', $file);

        $this->sharingService->revokeOwnFileShare($request->user(), $file, $request);

        return back()->with('status', 'Removed file from your shared items.');
    }

    public function revokeOwnFolderShare(Request $request, Folder $folder): RedirectResponse
    {
        $this->authorize('view', $folder);

        $this->sharingService->revokeOwnFolderShare($request->user(), $folder, $request);

        return back()->with('status', 'Removed folder from your shared items.');
    }

    public function createLink(ShareLinkCreateRequest $request, File $file): RedirectResponse
    {
        $this->authorize('share', $file);

        $link = $this->sharingService->createShareLink(
            actor: $request->user(),
            file: $file,
            input: $request->validated(),
            request: $request,
        );

        return back()->with([
            'status' => 'Share link created.',
            'share_link_url' => route('public.share.show', ['token' => $link->token]),
            'share_link_public_id' => $link->public_id,
        ]);
    }

    public function listLinks(Request $request, File $file): JsonResponse
    {
        $this->authorize('share', $file);

        return response()->json([
            'data' => $this->sharingService->listShareLinks($file),
        ]);
    }

    public function updateLink(ShareLinkUpdateRequest $request, ShareLink $shareLink): RedirectResponse
    {
        $this->authorize('share', $shareLink->file);

        $this->sharingService->updateShareLink(
            actor: $request->user(),
            shareLink: $shareLink,
            input: $request->validated(),
            request: $request,
        );

        return back()->with('status', 'Share link updated.');
    }

    public function revokeLink(Request $request, ShareLink $shareLink): RedirectResponse
    {
        $this->authorize('share', $shareLink->file);

        $this->sharingService->revokeShareLink($request->user(), $shareLink, $request);

        return back()->with('status', 'Share link revoked.');
    }
}
