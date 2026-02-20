<?php

namespace Tests\Unit\Jobs;

use App\Contracts\AntivirusScanner;
use App\Data\AntivirusScanResult;
use App\Jobs\VirusScanJob;
use App\Services\AuditLogService;
use App\Services\StorageDiskResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class VirusScanJobTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_infected_file_is_quarantined_and_audited(): void
    {
        $disk = $this->fileStorageDisk();
        Storage::fake($disk);

        $owner = $this->createUser();
        $folder = $this->createPrivateFolder($owner);
        $file = $this->createFile($folder, $owner, [
            'stored_name' => 'infected.txt',
            'storage_path' => 'private/'.$owner->public_id.'/'.$folder->public_id.'/2026/02/infected.txt',
        ]);

        $originalPath = $file->storage_path;
        Storage::disk($disk)->put($originalPath, 'infected-file-content');

        $scanner = $this->createMock(AntivirusScanner::class);
        $scanner->expects($this->once())
            ->method('scan')
            ->with($disk, $originalPath)
            ->willReturn(new AntivirusScanResult(
                infected: true,
                signature: 'Eicar-Test-Signature',
                raw: 'stream: Eicar-Test-Signature FOUND',
            ));

        $job = new VirusScanJob($file->id);
        $job->handle(
            $scanner,
            app(AuditLogService::class),
            app(StorageDiskResolver::class),
        );

        $file->refresh();

        $this->assertTrue($file->is_deleted);
        $this->assertNotNull($file->deleted_at);
        $this->assertNotSame($originalPath, $file->storage_path);
        $this->assertStringStartsWith('quarantine/', $file->storage_path);
        Storage::disk($disk)->assertMissing($originalPath);
        Storage::disk($disk)->assertExists($file->storage_path);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'file.quarantined',
            'entity_type' => 'file',
            'entity_id' => $file->id,
        ]);
    }

    public function test_clean_file_remains_untouched(): void
    {
        $disk = $this->fileStorageDisk();
        Storage::fake($disk);

        $owner = $this->createUser();
        $folder = $this->createPrivateFolder($owner);
        $file = $this->createFile($folder, $owner, [
            'stored_name' => 'clean.txt',
            'storage_path' => 'private/'.$owner->public_id.'/'.$folder->public_id.'/2026/02/clean.txt',
        ]);

        $originalPath = $file->storage_path;
        Storage::disk($disk)->put($originalPath, 'clean-content');

        $scanner = $this->createMock(AntivirusScanner::class);
        $scanner->expects($this->once())
            ->method('scan')
            ->with($disk, $originalPath)
            ->willReturn(new AntivirusScanResult(
                infected: false,
                signature: null,
                raw: 'stream: OK',
            ));

        $job = new VirusScanJob($file->id);
        $job->handle(
            $scanner,
            app(AuditLogService::class),
            app(StorageDiskResolver::class),
        );

        $file->refresh();

        $this->assertFalse($file->is_deleted);
        $this->assertSame($originalPath, $file->storage_path);
        Storage::disk($disk)->assertExists($file->storage_path);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'file.quarantined',
            'entity_id' => $file->id,
        ]);
    }

    public function test_fail_open_keeps_file_when_scanner_is_unavailable(): void
    {
        config(['antivirus.fail_open' => true]);
        $disk = $this->fileStorageDisk();
        Storage::fake($disk);

        $owner = $this->createUser();
        $folder = $this->createPrivateFolder($owner);
        $file = $this->createFile($folder, $owner, [
            'stored_name' => 'scan-error.txt',
            'storage_path' => 'private/'.$owner->public_id.'/'.$folder->public_id.'/2026/02/scan-error.txt',
        ]);

        $originalPath = $file->storage_path;
        Storage::disk($disk)->put($originalPath, 'content');

        $scanner = $this->createMock(AntivirusScanner::class);
        $scanner->expects($this->once())
            ->method('scan')
            ->with($disk, $originalPath)
            ->willThrowException(new RuntimeException('clamd unavailable'));

        $job = new VirusScanJob($file->id);
        $job->handle(
            $scanner,
            app(AuditLogService::class),
            app(StorageDiskResolver::class),
        );

        $file->refresh();

        $this->assertFalse($file->is_deleted);
        $this->assertSame($originalPath, $file->storage_path);
        Storage::disk($disk)->assertExists($originalPath);
    }
}
