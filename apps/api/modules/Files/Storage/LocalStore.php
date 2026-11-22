<?php

declare(strict_types=1);

namespace Modules\Files\Storage;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/** Files in the private storage folder of the application server. */
final class LocalStore implements FileStore
{
    private ?Filesystem $disk = null;

    public function __construct(private readonly string $root)
    {
        if ($root === '' || str_contains($root, '..') || str_contains($root, "\0")) {
            throw new \InvalidArgumentException('The local storage folder must be a plain folder name.');
        }
    }

    public function driver(): string
    {
        return 'storage.local';
    }

    public function disk(): Filesystem
    {
        return $this->disk ??= Storage::build([
            'driver' => 'local',
            'root' => storage_path('app/private/'.trim($this->root, '/')),
            'throw' => true,
            'visibility' => 'private',
        ]);
    }

    public function uploadTarget(string $path, string $mime, int $size, DateTimeInterface $expires): ?UploadTarget
    {
        return null;
    }

    public function downloadUrl(string $path, string $filename, string $mime, DateTimeInterface $expires): ?string
    {
        return null;
    }
}
