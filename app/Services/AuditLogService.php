<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogService
{
    public function log(
        ?User $actor,
        string $action,
        string $entityType,
        ?int $entityId = null,
        array $meta = [],
        ?Request $request = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'meta_json' => $meta,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }
}

