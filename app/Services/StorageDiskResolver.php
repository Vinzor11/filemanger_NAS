<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

class StorageDiskResolver
{
    private const SETTING_KEY = 'file_storage_disk';

    /**
     * @return list<string>
     */
    public function availableDisks(): array
    {
        $configured = array_keys((array) config('filesystems.disks', []));
        $supported = array_values(array_filter(
            ['local', 'nas'],
            fn (string $disk): bool => in_array($disk, $configured, true),
        ));

        if ($supported !== []) {
            return $supported;
        }

        if ($configured !== []) {
            return array_values($configured);
        }

        return ['local'];
    }

    public function resolve(?string $preferredDisk = null): string
    {
        $fallback = $this->fallbackDisk();

        if ($preferredDisk !== null && trim($preferredDisk) !== '') {
            return $this->normalizeDisk($preferredDisk, $fallback);
        }

        return $this->current();
    }

    public function current(): string
    {
        $fallback = $this->fallbackDisk();
        $stored = $this->readStoredDisk();

        if ($stored === null) {
            return $fallback;
        }

        return $this->normalizeDisk($stored, $fallback);
    }

    public function update(string $disk, ?int $updatedBy = null): string
    {
        $resolved = $this->resolve($disk);

        if (! $this->settingsTableExists()) {
            return $resolved;
        }

        AppSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            [
                'value' => $resolved,
                'updated_by' => $updatedBy,
            ],
        );

        return $resolved;
    }

    private function fallbackDisk(): string
    {
        $default = (string) config(
            'filesystems.file_storage_disk',
            config('filesystems.default', 'local'),
        );
        $available = $this->availableDisks();
        $safeDefault = $available[0] ?? 'local';

        return $this->normalizeDisk($default, $safeDefault);
    }

    private function readStoredDisk(): ?string
    {
        if (! $this->settingsTableExists()) {
            return null;
        }

        try {
            $value = AppSetting::query()
                ->where('key', self::SETTING_KEY)
                ->value('value');
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function settingsTableExists(): bool
    {
        try {
            return Schema::hasTable('app_settings');
        } catch (Throwable) {
            return false;
        }
    }

    private function normalizeDisk(string $disk, string $fallback): string
    {
        $candidate = trim($disk);

        return in_array($candidate, $this->availableDisks(), true)
            ? $candidate
            : $fallback;
    }
}
