<?php

namespace App\Contracts;

use App\Data\AntivirusScanResult;

interface AntivirusScanner
{
    public function scan(string $disk, string $path): AntivirusScanResult;
}

