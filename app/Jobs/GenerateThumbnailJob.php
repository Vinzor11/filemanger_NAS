<?php

namespace App\Jobs;

use App\Models\File;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateThumbnailJob implements ShouldQueue
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
    public function handle(): void
    {
        $file = File::query()->find($this->fileId);
        if (! $file) {
            return;
        }

        // Hook thumbnail generator here (images/PDF/video preview).
        Log::info('thumbnail.generated', [
            'file_id' => $file->id,
            'file_public_id' => $file->public_id,
        ]);
    }

    /**
     * Get retry delay with jitter.
     */
    public function backoff(): array
    {
        return [3 + random_int(0, 2), 10 + random_int(0, 5), 20 + random_int(0, 8)];
    }
}

