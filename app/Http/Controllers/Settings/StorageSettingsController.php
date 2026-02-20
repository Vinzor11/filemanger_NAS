<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StorageSettingsUpdateRequest;
use App\Services\AuditLogService;
use App\Services\StorageDiskResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StorageSettingsController extends Controller
{
    public function __construct(
        private readonly StorageDiskResolver $storageDiskResolver,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function edit(Request $request): Response
    {
        $options = array_map(
            static fn (string $disk): array => [
                'value' => $disk,
                'label' => strtoupper($disk),
            ],
            $this->storageDiskResolver->availableDisks(),
        );

        return Inertia::render('settings/storage', [
            'currentDisk' => $this->storageDiskResolver->current(),
            'availableDisks' => $options,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(StorageSettingsUpdateRequest $request): RedirectResponse
    {
        $previousDisk = $this->storageDiskResolver->current();
        $updatedDisk = $this->storageDiskResolver->update(
            disk: $request->validated('storage_disk'),
            updatedBy: $request->user()?->id,
        );

        if ($previousDisk !== $updatedDisk) {
            $this->auditLogService->log(
                actor: $request->user(),
                action: 'settings.file_storage_disk.updated',
                entityType: 'settings',
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'previous_disk' => $previousDisk,
                    'updated_disk' => $updatedDisk,
                ],
                request: $request,
            );
        }

        return back()->with('status', "File storage disk updated to {$updatedDisk}.");
    }
}
