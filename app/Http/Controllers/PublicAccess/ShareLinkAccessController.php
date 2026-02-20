<?php

namespace App\Http\Controllers\PublicAccess;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicAccess\PublicShareAccessRequest;
use App\Models\ShareLink;
use App\Services\AuditLogService;
use App\Services\SharingService;
use App\Services\StorageDiskResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShareLinkAccessController extends Controller
{
    public function __construct(
        private readonly SharingService $sharingService,
        private readonly AuditLogService $auditLogService,
        private readonly StorageDiskResolver $storageDiskResolver,
    ) {
    }

    public function show(string $token): Response
    {
        $link = ShareLink::query()->with('file')->where('token', $token)->first();

        return Inertia::render('public/share-access', [
            'exists' => (bool) $link,
            'requires_password' => $link?->password_hash !== null,
            'is_accessible' => $link?->isAccessible() ?? false,
            'file_name' => $link?->file?->original_name,
            'expires_at' => optional($link?->expires_at)->toIso8601String(),
            'download_count' => $link?->download_count,
            'max_downloads' => $link?->max_downloads,
            'token' => $token,
        ]);
    }

    public function access(PublicShareAccessRequest $request, string $token): RedirectResponse
    {
        $link = ShareLink::query()->with('file')->where('token', $token)->firstOrFail();
        $this->sharingService->validatePublicAccess($link, $request->validated('password'));

        $request->session()->put("share_access.{$token}", true);

        $this->auditLogService->log(
            actor: null,
            action: 'share_link.accessed',
            entityType: 'share_link',
            entityId: $link->id,
            meta: [
                'request_id' => $request->attributes->get('request_id'),
                'share_link_public_id' => $link->public_id,
            ],
            request: $request,
        );

        return back()->with('status', 'Share link access granted.');
    }

    public function download(Request $request, string $token): StreamedResponse
    {
        $link = ShareLink::query()->with('file')->where('token', $token)->firstOrFail();

        if ($link->password_hash !== null && ! $request->session()->get("share_access.{$token}")) {
            abort(403, 'Password verification is required.');
        }

        $this->sharingService->validatePublicAccess($link, null);
        $this->sharingService->incrementDownloadCount($link);

        $this->auditLogService->log(
            actor: null,
            action: 'share_link.downloaded',
            entityType: 'share_link',
            entityId: $link->id,
            meta: [
                'request_id' => $request->attributes->get('request_id'),
                'share_link_public_id' => $link->public_id,
                'file_public_id' => $link->file?->public_id,
            ],
            request: $request,
        );

        $file = $link->file;
        abort_unless($file !== null, 404);
        $disk = $this->storageDiskResolver->resolve($file->storage_disk);

        return Storage::disk($disk)->download($file->storage_path, $file->original_name);
    }
}
