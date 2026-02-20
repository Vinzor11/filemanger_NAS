<?php

namespace App\Jobs;

use App\Contracts\AntivirusScanner;
use App\Services\AuditLogService;
use App\Services\StorageDiskResolver;
use App\Models\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class VirusScanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $fileId,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(
        AntivirusScanner $scanner,
        AuditLogService $auditLogService,
        StorageDiskResolver $storageDiskResolver,
    ): void
    {
        $file = File::query()->find($this->fileId);
        if (! $file) {
            return;
        }

        $diskName = $storageDiskResolver->resolve($file->storage_disk);

        try {
            $result = $scanner->scan($diskName, $file->storage_path);
        } catch (Throwable $exception) {
            if ((bool) config('antivirus.fail_open', false)) {
                Log::warning('virus_scan.failed_open', [
                    'file_id' => $file->id,
                    'file_public_id' => $file->public_id,
                    'error' => $exception->getMessage(),
                ]);

                return;
            }

            throw $exception;
        }

        if ($result->infected) {
            DB::transaction(function () use ($file, $result, $auditLogService, $storageDiskResolver): void {
                $record = File::query()->lockForUpdate()->find($file->id);
                if (! $record) {
                    return;
                }

                $recordDiskName = $storageDiskResolver->resolve($record->storage_disk);
                $disk = Storage::disk($recordDiskName);
                $quarantinePrefix = trim((string) config('antivirus.quarantine_prefix', 'quarantine'), '/');
                $quarantinePath = "{$quarantinePrefix}/".now()->format('Y/m/d')."/{$record->public_id}_{$record->stored_name}";

                if ($disk->exists($record->storage_path)) {
                    $disk->move($record->storage_path, $quarantinePath);
                }

                $record->update([
                    'is_deleted' => true,
                    'deleted_at' => now(),
                    'storage_path' => $quarantinePath,
                ]);

                $auditLogService->log(
                    actor: null,
                    action: 'file.quarantined',
                    entityType: 'file',
                    entityId: $record->id,
                    meta: [
                        'reason' => 'virus_detected',
                        'signature' => $result->signature,
                        'scan_response' => $result->raw,
                        'file_public_id' => $record->public_id,
                    ],
                );
            });

            return;
        }

        Log::info('virus_scan.clean', [
            'file_id' => $file->id,
            'file_public_id' => $file->public_id,
            'scan_response' => $result->raw,
        ]);
    }

    /**
     * Get retry delay with jitter.
     */
    public function backoff(): array
    {
        return [5 + random_int(0, 3), 15 + random_int(0, 5), 30 + random_int(0, 10)];
    }
}
