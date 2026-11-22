<?php

declare(strict_types=1);

namespace Modules\Files\Scanning;

use Closure;

/**
 * ClamAV daemon over TCP using the INSTREAM command: the file is sent in chunks,
 * each prefixed with its length as a 4-byte big-endian number, ending with a
 * zero-length chunk. clamd answers "stream: OK" or "stream: <name> FOUND".
 */
final class ClamdScanner implements Scanner
{
    private const CHUNK = 65536;

    /** @param  Closure(): resource  $connect  opens the connection to clamd */
    public function __construct(private readonly Closure $connect) {}

    public static function tcp(string $host, int $port, int $timeout): self
    {
        return new self(function () use ($host, $port, $timeout) {
            $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, $timeout);

            if ($socket === false) {
                throw new ScannerUnavailable("The virus scanner is not reachable ({$error}).");
            }

            stream_set_timeout($socket, $timeout);

            return $socket;
        });
    }

    public function scan($stream): ScanResult
    {
        $socket = ($this->connect)();

        try {
            $this->write($socket, "zINSTREAM\0");

            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK);
                if ($chunk === false) {
                    throw new ScannerUnavailable('Could not read the file to scan it.');
                }
                if ($chunk === '') {
                    continue;
                }
                $this->write($socket, pack('N', strlen($chunk)).$chunk);
            }

            $this->write($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($socket);
        }

        if (str_ends_with($reply, 'OK')) {
            return ScanResult::clean();
        }

        if (preg_match('/^stream: (.+) FOUND$/', $reply, $m) === 1) {
            return ScanResult::infected($m[1]);
        }

        throw new ScannerUnavailable('The virus scanner gave an unexpected answer: '.mb_substr($reply, 0, 200));
    }

    /** @param  resource  $socket */
    private function write($socket, string $data): void
    {
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $n = fwrite($socket, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new ScannerUnavailable('The connection to the virus scanner was lost.');
            }
            $written += $n;
        }
    }
}
