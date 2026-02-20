<?php

namespace App\Data;

class AntivirusScanResult
{
    public function __construct(
        public readonly bool $infected,
        public readonly ?string $signature = null,
        public readonly ?string $raw = null,
    ) {
    }

    public function isClean(): bool
    {
        return ! $this->infected;
    }
}

