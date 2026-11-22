<?php

declare(strict_types=1);

namespace Modules\Files\Storage;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/** Amazon S3, or any S3-compatible store (MinIO, Wasabi, Cloudflare R2). */
final class S3Store implements FileStore
{
    private ?AwsS3V3Adapter $disk = null;

    /** @param  array<string, mixed>  $values */
    public function __construct(private readonly array $values) {}

    public function driver(): string
    {
        return 'storage.s3';
    }

    public function disk(): Filesystem
    {
        if ($this->disk === null) {
            $endpoint = $this->values['endpoint'] ?? null;
            /** @var AwsS3V3Adapter $disk */
            $disk = Storage::build(array_filter([
                'driver' => 's3',
                'key' => $this->values['key'] ?? null,
                'secret' => $this->values['secret'] ?? null,
                'region' => $this->values['region'] ?? 'af-south-1',
                'bucket' => $this->values['bucket'] ?? null,
                'root' => trim((string) ($this->values['prefix'] ?? ''), '/') ?: null,
                'endpoint' => is_string($endpoint) && $endpoint !== '' ? $endpoint : null,
                'use_path_style_endpoint' => (bool) ($this->values['path_style'] ?? false),
                'visibility' => 'private',
                'throw' => true,
                // Fail fast in a connection test rather than retrying for a minute.
                'retries' => 2,
                'http' => ['connect_timeout' => 5, 'timeout' => 60],
            ], fn (mixed $v): bool => $v !== null));
            $this->disk = $disk;
        }

        return $this->disk;
    }

    public function uploadTarget(string $path, string $mime, int $size, DateTimeInterface $expires): UploadTarget
    {
        $signed = $this->adapter()->temporaryUploadUrl($path, $expires, [
            'ContentType' => $mime,
            'ContentLength' => $size,
        ]);

        $headers = ['Content-Type' => $mime];
        foreach ($signed['headers'] as $name => $value) {
            $name = (string) $name;
            // The browser sets Host and Content-Length itself.
            if (! in_array(strtolower($name), ['host', 'content-length'], true)) {
                $headers[$name] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
        }

        return new UploadTarget('PUT', (string) $signed['url'], $headers);
    }

    public function downloadUrl(string $path, string $filename, string $mime, DateTimeInterface $expires): string
    {
        return $this->adapter()->temporaryUrl($path, $expires, [
            'ResponseContentType' => $mime,
            'ResponseContentDisposition' => ContentDisposition::attachment($filename),
        ]);
    }

    private function adapter(): AwsS3V3Adapter
    {
        $this->disk();

        /** @var AwsS3V3Adapter */
        return $this->disk;
    }
}
