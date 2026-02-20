<?php

namespace App\Jobs;

use App\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EnrichAuditLogJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $auditLogId,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $log = AuditLog::query()->find($this->auditLogId);
        if (! $log) {
            return;
        }

        $meta = $log->meta_json ?? [];
        $meta['enriched_at'] = now()->toIso8601String();

        $log->update([
            'meta_json' => $meta,
        ]);
    }

    /**
     * Get retry delay with jitter.
     */
    public function backoff(): array
    {
        return [2 + random_int(0, 2), 6 + random_int(0, 4), 15 + random_int(0, 5), 30 + random_int(0, 8)];
    }
}

