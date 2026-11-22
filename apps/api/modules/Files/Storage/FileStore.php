<?php

declare(strict_types=1);

namespace Modules\Files\Storage;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;

/** A place files are kept: local disk, Amazon S3 or Azure Blob. */
interface FileStore
{
    public function driver(): string;

    /** Laravel filesystem for reading, writing and deleting. */
    public function disk(): Filesystem;

    /**
     * Where the client sends the bytes. Cloud stores give a signed URL so uploads
     * go straight to them; the local store returns null (upload through the API).
     */
    public function uploadTarget(string $path, string $mime, int $size, DateTimeInterface $expires): ?UploadTarget;

    /** A short-lived link to download the file, or null to stream through the API. */
    public function downloadUrl(string $path, string $filename, string $mime, DateTimeInterface $expires): ?string;
}
