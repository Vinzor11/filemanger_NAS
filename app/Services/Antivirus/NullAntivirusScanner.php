<?php

namespace App\Services\Antivirus;

use App\Contracts\AntivirusScanner;
use App\Data\AntivirusScanResult;

class NullAntivirusScanner implements AntivirusScanner
{
    public function scan(string $disk, string $path): AntivirusScanResult
    {
        return new AntivirusScanResult(false, null, 'antivirus-disabled');
    }
}

