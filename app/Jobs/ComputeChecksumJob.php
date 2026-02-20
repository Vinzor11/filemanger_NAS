<?php

namespace App\Jobs;

use App\Models\File;
use App\Services\StorageDiskResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ComputeChecksumJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

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
    public function handle(StorageDiskResolver $storageDiskResolver): void
    {
        $file = File::query()->find($this->fileId);
        if (! $file) {
            return;
        }

        $diskName = $storageDiskResolver->resolve($file->storage_disk);
        $disk = Storage::disk($diskName);
        if (! $disk->exists($file->storage_path)) {
            return;
        }

        $stream = $disk->readStream($file->storage_path);
        if (! $stream) {
            return;
        }

        $hashContext = hash_init('sha256');
        hash_update_stream($hashContext, $stream);
        fclose($stream);
        $checksum = hash_final($hashContext);

        $file->update([
            'checksum_sha256' => $checksum,
        ]);
    }

    /**
     * Get the retry delay with jitter.
     */
    public function backoff(): array
    {
        return [2 + random_int(0, 2), 8 + random_int(0, 4), 20 + random_int(0, 6), 40 + random_int(0, 10)];
    }
}
