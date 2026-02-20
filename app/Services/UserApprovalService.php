<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserApprovalService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * @param  list<string>  $roles
     */
    public function approve(User $actor, User $target, array $roles, Request $request): User
    {
        return DB::transaction(function () use ($actor, $target, $roles, $request): User {
            $user = User::query()->lockForUpdate()->findOrFail($target->id);

            if ($user->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending users can be approved.',
                ]);
            }

            $user->update([
                'status' => 'active',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);

            $user->syncRoles($roles);

            $this->auditLogService->log(
                actor: $actor,
                action: 'user.approved',
                entityType: 'user',
                entityId: $user->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'target_user_public_id' => $user->public_id,
                    'assigned_roles' => $roles,
                    'idempotency_key' => $request->header('X-Idempotency-Key'),
                ],
                request: $request,
            );

            return $user->fresh(['roles', 'employee']);
        });
    }

    public function reject(User $actor, User $target, string $reason, Request $request): User
    {
        return DB::transaction(function () use ($actor, $target, $reason, $request): User {
            $user = User::query()->lockForUpdate()->findOrFail($target->id);

            if ($user->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending users can be rejected.',
                ]);
            }

            $user->update([
                'status' => 'rejected',
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->auditLogService->log(
                actor: $actor,
                action: 'user.rejected',
                entityType: 'user',
                entityId: $user->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'target_user_public_id' => $user->public_id,
                    'reason' => $reason,
                    'idempotency_key' => $request->header('X-Idempotency-Key'),
                ],
                request: $request,
            );

            return $user;
        });
    }
}

