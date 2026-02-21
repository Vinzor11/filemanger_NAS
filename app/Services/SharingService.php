<?php

namespace App\Services;

use App\Models\File;
use App\Models\FilePermission;
use App\Models\Folder;
use App\Models\FolderPermission;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SharingService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $shares
     */
    public function upsertUserShares(User $actor, File $file, array $shares, Request $request): void
    {
        DB::transaction(function () use ($actor, $file, $shares, $request): void {
            $record = File::query()->lockForUpdate()->findOrFail($file->id);

            foreach ($shares as $share) {
                $canView = (bool) ($share['can_view'] ?? true);
                $canDownload = (bool) ($share['can_download'] ?? false);
                $canEdit = (bool) ($share['can_edit'] ?? false);
                $canDelete = (bool) ($share['can_delete'] ?? false);

                FilePermission::query()->updateOrCreate(
                    [
                        'file_id' => $record->id,
                        'user_id' => $share['user_id'],
                    ],
                    [
                        'can_view' => $canView,
                        'can_download' => $canDownload,
                        'can_edit' => $canEdit,
                        'can_delete' => $canDelete,
                        'created_by' => $actor->id,
                    ],
                );
            }

            $this->auditLogService->log(
                actor: $actor,
                action: 'file.shared_to_users',
                entityType: 'file',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'file_public_id' => $record->public_id,
                    'share_count' => count($shares),
                ],
                request: $request,
            );
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $shares
     */
    public function upsertFolderShares(User $actor, Folder $folder, array $shares, Request $request): void
    {
        DB::transaction(function () use ($actor, $folder, $shares, $request): void {
            $record = Folder::query()->lockForUpdate()->findOrFail($folder->id);

            foreach ($shares as $share) {
                $canView = (bool) ($share['can_view'] ?? true);
                $canEdit = (bool) ($share['can_edit'] ?? false);
                $canUpload = array_key_exists('can_upload', $share)
                    ? (bool) $share['can_upload']
                    : $canEdit;
                $canDelete = (bool) ($share['can_delete'] ?? false);

                FolderPermission::query()->updateOrCreate(
                    [
                        'folder_id' => $record->id,
                        'user_id' => $share['user_id'],
                    ],
                    [
                        'can_view' => $canView,
                        'can_upload' => $canUpload,
                        'can_edit' => $canEdit,
                        'can_delete' => $canDelete,
                        'created_by' => $actor->id,
                    ],
                );
            }

            $this->auditLogService->log(
                actor: $actor,
                action: 'folder.shared_to_users',
                entityType: 'folder',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'folder_public_id' => $record->public_id,
                    'share_count' => count($shares),
                ],
                request: $request,
            );
        });
    }

    /**
     * @return SupportCollection<int, array{
     *   id:int,
     *   public_id:string,
     *   email:string,
     *   name:string,
     *   department:array{id:int,name:string,code:string}|null
     * }>
     */
    public function availableEmployees(User $actor, ?string $search = null, int $limit = 100): SupportCollection
    {
        $needle = trim((string) $search);
        $effectiveLimit = max(10, min($limit, 200));

        return User::query()
            ->where('status', 'active')
            ->where('id', '!=', $actor->id)
            ->whereHas('employee', fn (Builder $query) => $query->where('status', 'active'))
            ->with([
                'employee.department:id,name,code',
            ])
            ->when($needle !== '', function (Builder $query) use ($needle): void {
                $query->where(function (Builder $inner) use ($needle): void {
                    $inner->where('email', 'like', "%{$needle}%")
                        ->orWhereHas('employee', function (Builder $employeeQuery) use ($needle): void {
                            $employeeQuery->where('first_name', 'like', "%{$needle}%")
                                ->orWhere('last_name', 'like', "%{$needle}%")
                                ->orWhere('employee_no', 'like', "%{$needle}%");
                        });
                });
            })
            ->orderBy('email')
            ->limit($effectiveLimit)
            ->get()
            ->map(function (User $candidate): array {
                $fullName = trim(($candidate->employee?->first_name ?? '').' '.($candidate->employee?->last_name ?? ''));
                $department = $candidate->employee?->department;

                return [
                    'id' => $candidate->id,
                    'public_id' => $candidate->public_id,
                    'email' => $candidate->email,
                    'name' => $fullName !== '' ? $fullName : $candidate->email,
                    'department' => $department ? [
                        'id' => $department->id,
                        'name' => $department->name,
                        'code' => $department->code,
                    ] : null,
                ];
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $permissions
     */
    public function shareToDepartment(User $actor, File $file, array $permissions, Request $request): File
    {
        return DB::transaction(function () use ($actor, $file, $permissions, $request): File {
            $departmentId = $actor->employee?->department_id;
            if (! $departmentId) {
                throw ValidationException::withMessages([
                    'department' => 'Your account does not have a department assigned.',
                ]);
            }

            $record = File::query()->lockForUpdate()->findOrFail($file->id);
            $departmentPermissions = $this->normalizeFileDepartmentPermissions($permissions);
            $record->update([
                'department_id' => $departmentId,
                'visibility' => 'department',
            ]);
            $departmentUserIds = $this->departmentMemberUserIds($departmentId);

            foreach ($departmentUserIds as $userId) {
                FilePermission::query()->updateOrCreate(
                    [
                        'file_id' => $record->id,
                        'user_id' => $userId,
                    ],
                    [
                        'can_view' => $departmentPermissions['can_view'],
                        'can_download' => $departmentPermissions['can_download'],
                        'can_edit' => $departmentPermissions['can_edit'],
                        'can_delete' => $departmentPermissions['can_delete'],
                        'created_by' => $actor->id,
                    ],
                );
            }

            $this->auditLogService->log(
                actor: $actor,
                action: 'file.shared_to_department',
                entityType: 'file',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'file_public_id' => $record->public_id,
                    'department_id' => $departmentId,
                    'share_count' => count($departmentUserIds),
                    'permissions' => $departmentPermissions,
                ],
                request: $request,
            );

            return $record->fresh(['department:id,name,code']);
        });
    }

    /**
     * @param  array<string, mixed>  $permissions
     */
    public function shareFolderToDepartment(User $actor, Folder $folder, array $permissions, Request $request): Folder
    {
        return DB::transaction(function () use ($actor, $folder, $permissions, $request): Folder {
            $departmentId = $actor->employee?->department_id;
            if (! $departmentId) {
                throw ValidationException::withMessages([
                    'department' => 'Your account does not have a department assigned.',
                ]);
            }

            $record = Folder::query()->lockForUpdate()->findOrFail($folder->id);
            $departmentPermissions = $this->normalizeFolderDepartmentPermissions($permissions);
            $departmentUserIds = $this->departmentMemberUserIds($departmentId);

            foreach ($departmentUserIds as $userId) {
                FolderPermission::query()->updateOrCreate(
                    [
                        'folder_id' => $record->id,
                        'user_id' => $userId,
                    ],
                    [
                        'can_view' => $departmentPermissions['can_view'],
                        'can_upload' => $departmentPermissions['can_upload'],
                        'can_edit' => $departmentPermissions['can_edit'],
                        'can_delete' => $departmentPermissions['can_delete'],
                        'created_by' => $actor->id,
                    ],
                );
            }

            if ($record->visibility === 'private') {
                $record->update(['visibility' => 'shared']);
            }

            $this->auditLogService->log(
                actor: $actor,
                action: 'folder.shared_to_department',
                entityType: 'folder',
                entityId: $record->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'folder_public_id' => $record->public_id,
                    'department_id' => $departmentId,
                    'share_count' => count($departmentUserIds),
                    'permissions' => $departmentPermissions,
                ],
                request: $request,
            );

            return $record->fresh(['department:id,name,code']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createShareLink(User $actor, File $file, array $input, Request $request): ShareLink
    {
        return DB::transaction(function () use ($actor, $file, $input, $request): ShareLink {
            $record = File::query()->lockForUpdate()->findOrFail($file->id);

            $link = ShareLink::query()->create([
                'file_id' => $record->id,
                'token' => bin2hex(random_bytes(32)),
                'expires_at' => $input['expires_at'] ?? null,
                'max_downloads' => $input['max_downloads'] ?? null,
                'download_count' => 0,
                'password_hash' => ! empty($input['password']) ? Hash::make($input['password']) : null,
                'created_by' => $actor->id,
                'revoked_at' => null,
            ]);

            $this->auditLogService->log(
                actor: $actor,
                action: 'share_link.created',
                entityType: 'share_link',
                entityId: $link->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'share_link_public_id' => $link->public_id,
                    'file_public_id' => $record->public_id,
                    'expires_at' => optional($link->expires_at)->toIso8601String(),
                    'max_downloads' => $link->max_downloads,
                    'password_protected' => $link->password_hash !== null,
                ],
                request: $request,
            );

            return $link;
        });
    }

    public function revokeShareLink(User $actor, ShareLink $shareLink, Request $request): ShareLink
    {
        return DB::transaction(function () use ($actor, $shareLink, $request): ShareLink {
            $link = ShareLink::query()->lockForUpdate()->findOrFail($shareLink->id);
            $link->update(['revoked_at' => now()]);

            $this->auditLogService->log(
                actor: $actor,
                action: 'share_link.revoked',
                entityType: 'share_link',
                entityId: $link->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'share_link_public_id' => $link->public_id,
                    'idempotency_key' => $request->header('X-Idempotency-Key'),
                ],
                request: $request,
            );

            return $link;
        });
    }

    /**
     * @return Collection<int, FilePermission>
     */
    public function listUserShares(File $file): Collection
    {
        return FilePermission::query()
            ->where('file_id', $file->id)
            ->where('can_view', true)
            ->with('user:id,public_id,email')
            ->orderBy('user_id')
            ->get();
    }

    /**
     * @return Collection<int, FolderPermission>
     */
    public function listFolderUserShares(Folder $folder): Collection
    {
        return FolderPermission::query()
            ->where('folder_id', $folder->id)
            ->where('can_view', true)
            ->with('user:id,public_id,email')
            ->orderBy('user_id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateUserShare(User $actor, File $file, User $targetUser, array $input, Request $request): FilePermission
    {
        return DB::transaction(function () use ($actor, $file, $targetUser, $input, $request): FilePermission {
            $permission = FilePermission::query()
                ->where('file_id', $file->id)
                ->where('user_id', $targetUser->id)
                ->lockForUpdate()
                ->first();

            if (! $permission) {
                throw ValidationException::withMessages([
                    'share' => 'Share permission does not exist for this user.',
                ]);
            }

            $permission->update([
                'can_view' => array_key_exists('can_view', $input) ? (bool) $input['can_view'] : $permission->can_view,
                'can_download' => array_key_exists('can_download', $input) ? (bool) $input['can_download'] : $permission->can_download,
                'can_edit' => array_key_exists('can_edit', $input) ? (bool) $input['can_edit'] : $permission->can_edit,
                'can_delete' => array_key_exists('can_delete', $input) ? (bool) $input['can_delete'] : $permission->can_delete,
            ]);

            $this->auditLogService->log(
                actor: $actor,
                action: 'share.user.updated',
                entityType: 'file',
                entityId: $file->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'file_public_id' => $file->public_id,
                    'target_user_public_id' => $targetUser->public_id,
                    'permissions' => [
                        'can_view' => $permission->can_view,
                        'can_download' => $permission->can_download,
                        'can_edit' => $permission->can_edit,
                        'can_delete' => $permission->can_delete,
                    ],
                ],
                request: $request,
            );

            return $permission->fresh(['user']);
        });
    }

    public function revokeUserShare(User $actor, File $file, User $targetUser, Request $request): void
    {
        DB::transaction(function () use ($actor, $file, $targetUser, $request): void {
            $deleted = FilePermission::query()
                ->where('file_id', $file->id)
                ->where('user_id', $targetUser->id)
                ->delete();

            if (! $deleted) {
                throw ValidationException::withMessages([
                    'share' => 'Share permission does not exist for this user.',
                ]);
            }

            $this->auditLogService->log(
                actor: $actor,
                action: 'share.user.revoked',
                entityType: 'file',
                entityId: $file->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'file_public_id' => $file->public_id,
                    'target_user_public_id' => $targetUser->public_id,
                ],
                request: $request,
            );
        });
    }

    public function revokeOwnFileShare(User $actor, File $file, Request $request): void
    {
        DB::transaction(function () use ($actor, $file, $request): void {
            $deleted = FilePermission::query()
                ->where('file_id', $file->id)
                ->where('user_id', $actor->id)
                ->delete();

            if (! $deleted) {
                throw ValidationException::withMessages([
                    'share' => 'You do not have a direct share on this file.',
                ]);
            }

            $this->auditLogService->log(
                actor: $actor,
                action: 'share.self_revoked',
                entityType: 'file',
                entityId: $file->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'file_public_id' => $file->public_id,
                ],
                request: $request,
            );
        });
    }

    public function revokeOwnFolderShare(User $actor, Folder $folder, Request $request): void
    {
        DB::transaction(function () use ($actor, $folder, $request): void {
            $deleted = FolderPermission::query()
                ->where('folder_id', $folder->id)
                ->where('user_id', $actor->id)
                ->delete();

            if (! $deleted) {
                throw ValidationException::withMessages([
                    'share' => 'You do not have a direct share on this folder.',
                ]);
            }

            $this->auditLogService->log(
                actor: $actor,
                action: 'share.self_revoked',
                entityType: 'folder',
                entityId: $folder->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'folder_public_id' => $folder->public_id,
                ],
                request: $request,
            );
        });
    }

    public function revokeFolderUserShare(User $actor, Folder $folder, User $targetUser, Request $request): void
    {
        DB::transaction(function () use ($actor, $folder, $targetUser, $request): void {
            $deleted = FolderPermission::query()
                ->where('folder_id', $folder->id)
                ->where('user_id', $targetUser->id)
                ->delete();

            if (! $deleted) {
                throw ValidationException::withMessages([
                    'share' => 'Share permission does not exist for this user.',
                ]);
            }

            $this->auditLogService->log(
                actor: $actor,
                action: 'share.user.revoked',
                entityType: 'folder',
                entityId: $folder->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'folder_public_id' => $folder->public_id,
                    'target_user_public_id' => $targetUser->public_id,
                ],
                request: $request,
            );
        });
    }

    /**
     * @return Collection<int, ShareLink>
     */
    public function listShareLinks(File $file): Collection
    {
        return ShareLink::query()
            ->where('file_id', $file->id)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateShareLink(User $actor, ShareLink $shareLink, array $input, Request $request): ShareLink
    {
        return DB::transaction(function () use ($actor, $shareLink, $input, $request): ShareLink {
            $link = ShareLink::query()->lockForUpdate()->findOrFail($shareLink->id);

            $payload = [
                'expires_at' => $input['expires_at'] ?? $link->expires_at,
                'max_downloads' => $input['max_downloads'] ?? $link->max_downloads,
            ];

            if (array_key_exists('password', $input) && $input['password'] !== null) {
                $payload['password_hash'] = Hash::make((string) $input['password']);
            }

            $link->update($payload);

            $this->auditLogService->log(
                actor: $actor,
                action: 'share.link.updated',
                entityType: 'share_link',
                entityId: $link->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'share_link_public_id' => $link->public_id,
                    'expires_at' => optional($link->expires_at)->toIso8601String(),
                    'max_downloads' => $link->max_downloads,
                ],
                request: $request,
            );

            return $link->fresh();
        });
    }

    public function validatePublicAccess(ShareLink $link, ?string $password): void
    {
        if (! $link->isAccessible()) {
            throw ValidationException::withMessages([
                'token' => 'Share link is expired, revoked, or has reached download limits.',
            ]);
        }

        if ($link->password_hash !== null && ! Hash::check((string) $password, $link->password_hash)) {
            throw ValidationException::withMessages([
                'password' => 'Share link password is invalid.',
            ]);
        }
    }

    public function incrementDownloadCount(ShareLink $link): void
    {
        DB::transaction(function () use ($link): void {
            $query = ShareLink::query()
                ->where('id', $link->id)
                ->whereNull('revoked_at');

            if ($link->max_downloads !== null) {
                $query->whereRaw('download_count < max_downloads');
            }

            $updated = $query->increment('download_count');
            if (! $updated) {
                throw ValidationException::withMessages([
                    'token' => 'Download limit has been reached.',
                ]);
            }
        });
    }

    /**
     * @return list<int>
     */
    private function departmentMemberUserIds(int $departmentId): array
    {
        return User::query()
            ->where('status', 'active')
            ->whereHas('employee', function (Builder $query) use ($departmentId): void {
                $query->where('status', 'active')
                    ->where('department_id', $departmentId);
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $permissions
     * @return array{
     *   can_view:bool,
     *   can_download:bool,
     *   can_edit:bool,
     *   can_delete:bool
     * }
     */
    private function normalizeFileDepartmentPermissions(array $permissions): array
    {
        return [
            'can_view' => (bool) ($permissions['can_view'] ?? true),
            'can_download' => (bool) ($permissions['can_download'] ?? false),
            'can_edit' => (bool) ($permissions['can_edit'] ?? false),
            'can_delete' => (bool) ($permissions['can_delete'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $permissions
     * @return array{
     *   can_view:bool,
     *   can_upload:bool,
     *   can_edit:bool,
     *   can_delete:bool
     * }
     */
    private function normalizeFolderDepartmentPermissions(array $permissions): array
    {
        $canEdit = (bool) ($permissions['can_edit'] ?? false);
        $canUpload = array_key_exists('can_upload', $permissions)
            ? (bool) $permissions['can_upload']
            : $canEdit;

        return [
            'can_view' => (bool) ($permissions['can_view'] ?? true),
            'can_upload' => $canUpload,
            'can_edit' => $canEdit,
            'can_delete' => (bool) ($permissions['can_delete'] ?? false),
        ];
    }
}
