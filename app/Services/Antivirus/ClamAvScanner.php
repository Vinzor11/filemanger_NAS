<?php

namespace App\Services\Antivirus;

use App\Contracts\AntivirusScanner;
use App\Data\AntivirusScanResult;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ClamAvScanner implements AntivirusScanner
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function scan(string $disk, string $path): AntivirusScanResult
    {
        $storage = Storage::disk($disk);
        if (! $storage->exists($path)) {
            throw new RuntimeException("Cannot scan missing file at path '{$path}'.");
        }

        $stream = $storage->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('Cannot open stream for antivirus scan.');
        }

        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errorCode,
            $errorMessage,
            $this->timeoutSeconds
        );

        if (! is_resource($socket)) {
            fclose($stream);
            throw new RuntimeException("Unable to connect to clamd ({$errorCode}): {$errorMessage}");
        }

        stream_set_timeout($socket, $this->timeoutSeconds);

        // zINSTREAM with null terminator is clamd's streaming protocol command.
        fwrite($socket, "zINSTREAM\0");

        while (! feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                fclose($stream);
                fclose($socket);
                throw new RuntimeException('Failed reading upload stream during antivirus scan.');
            }

            $length = strlen($chunk);
            if ($length === 0) {
                continue;
            }

            fwrite($socket, pack('N', $length).$chunk);
        }

        // Signal end of stream.
        fwrite($socket, pack('N', 0));

        $response = '';
        while (! feof($socket)) {
            $line = fgets($socket);
            if ($line === false) {
                break;
            }
            $response .= $line;
        }

        fclose($stream);
        fclose($socket);

        $normalized = trim($response);
        if ($normalized === '') {
            throw new RuntimeException('Empty response received from clamd.');
        }

        if (str_contains($normalized, 'FOUND')) {
            preg_match('/:\s(.+)\sFOUND$/', $normalized, $matches);
            $signature = $matches[1] ?? 'unknown-signature';

            return new AntivirusScanResult(true, $signature, $normalized);
        }

        if (str_contains($normalized, 'OK')) {
            return new AntivirusScanResult(false, null, $normalized);
        }

        throw new RuntimeException("Unexpected clamd response: {$normalized}");
    }
}

