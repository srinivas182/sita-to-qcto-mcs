<?php

declare(strict_types=1);

namespace Modules\Files\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Audit\AuditLogger;
use Modules\Files\Exceptions\FileError;
use Modules\Files\Models\File;
use Modules\Files\Scanning\Scanner;
use Modules\Files\Scanning\ScannerUnavailable;
use Modules\Files\Services\ImageVariants;
use Modules\Files\Storage\StoreFactory;
use Modules\Files\Support\FileStatus;

/**
 * Checks an uploaded file before anyone can use it:
 *  - its content really is one of the purpose's allowed types (magic bytes);
 *  - the virus scanner passes it;
 *  - image variants are made where the purpose asks for them.
 * Runs in the tenant context it was dispatched from.
 */
final class ProcessFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    public function __construct(public readonly string $fileId) {}

    public function handle(StoreFactory $stores, Scanner $scanner, ImageVariants $images, AuditLogger $audit): void
    {
        $file = File::query()->find($this->fileId);

        if ($file === null || $file->status !== FileStatus::Processing) {
            return;
        }

        $disk = $stores->forFile($file)->disk();
        $config = $file->purposeConfig();

        // 1. Content type from the bytes, not from what the browser claimed.
        $head = $disk->readStream($file->path);
        if ($head === null) {
            throw new FileError('Stored file is missing.', 'missing', 500);
        }
        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer((string) fread($head, 8192)) ?: 'application/octet-stream';
        fclose($head);

        /** @var list<string> $allowed */
        $allowed = $config['mimes'] ?? [];
        if (! in_array($sniffed, $allowed, true)) {
            $this->reject($file, $disk, 'The content is not an allowed type of file.', $audit);

            return;
        }

        // 2. Virus scan and checksum in one pass over the file.
        $stream = $disk->readStream($file->path);
        if ($stream === null) {
            throw new FileError('Stored file is missing.', 'missing', 500);
        }
        $hash = hash_init('sha256');
        $tee = $this->hashingStream($stream, $hash);

        try {
            $result = $scanner->scan($tee);
        } catch (ScannerUnavailable $e) {
            if ($this->attempts() >= $this->tries) {
                $file->forceFill(['status' => FileStatus::Failed, 'status_reason' => 'The virus scanner was not available.'])->save();

                return;
            }

            throw $e;
        } finally {
            fclose($tee);
            fclose($stream);
        }

        if (! $result->clean) {
            $audit->record('file.infected', $file, meta: ['signature' => $result->signature]);
            $this->reject($file, $disk, 'A virus was found in this file.', $audit);

            return;
        }

        // 3. Variants.
        $variants = [];
        /** @var array<string, int> $sizes */
        $sizes = $config['variants'] ?? [];

        if ($sizes !== []) {
            try {
                foreach ($images->make((string) $disk->get($file->path), $sizes) as $name => $variant) {
                    $path = "{$file->path}.{$name}.webp";
                    $disk->put($path, $variant['bytes']);
                    $variants[$name] = ['path' => $path, 'width' => $variant['width'], 'height' => $variant['height'], 'size' => strlen($variant['bytes'])];
                }
            } catch (FileError $e) {
                $this->reject($file, $disk, $e->getMessage(), $audit);

                return;
            }
        }

        $file->forceFill([
            'status' => FileStatus::Ready,
            'status_reason' => null,
            'mime' => $sniffed,
            'sha256' => hash_final($hash),
            'variants' => $variants === [] ? null : $variants,
            'scanned_at' => now(),
        ])->save();
    }

    private function reject(File $file, Filesystem $disk, string $reason, AuditLogger $audit): void
    {
        $disk->delete($file->path);
        $file->forceFill(['status' => FileStatus::Rejected, 'status_reason' => $reason, 'scanned_at' => now()])->save();
        $audit->record('file.rejected', $file, meta: ['reason' => $reason]);
    }

    /**
     * Copy a stream into a temporary one while hashing it, so the scanner and the
     * checksum read the file once from the store.
     *
     * @param  resource  $stream
     * @return resource
     */
    private function hashingStream($stream, \HashContext $hash)
    {
        $copy = fopen('php://temp/maxmemory:8388608', 'w+b');
        if ($copy === false) {
            throw new FileError('No temporary space.', 'temp', 500);
        }

        while (! feof($stream)) {
            $chunk = (string) fread($stream, 65536);
            hash_update($hash, $chunk);
            fwrite($copy, $chunk);
        }

        rewind($copy);

        return $copy;
    }
}
