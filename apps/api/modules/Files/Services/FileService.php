<?php

declare(strict_types=1);

namespace Modules\Files\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Audit\AuditLogger;
use Modules\Files\Exceptions\FileError;
use Modules\Files\Jobs\ProcessFile;
use Modules\Files\Models\File;
use Modules\Files\Storage\StoreFactory;
use Modules\Files\Storage\UploadTarget;
use Modules\Files\Support\FileStatus;
use Modules\Tenancy\Context\TenantContext;

/**
 * Upload flow:
 *  1. create(): checks purpose, type and size, records the file and returns where to
 *     send the bytes (a signed cloud URL, or the API for local storage);
 *  2. the client uploads; for local storage receive() takes the stream;
 *  3. complete(): checks the size and queues ProcessFile (type sniffing, virus scan,
 *     variants). Only then is the file usable.
 */
final class FileService
{
    public function __construct(
        private readonly StoreFactory $stores,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{purpose: string, name: string, mime: string, size: int}  $input
     * @return array{file: File, upload: UploadTarget|null}
     */
    public function create(array $input, ?string $ownerId): array
    {
        $purpose = (array) config("files.purposes.{$input['purpose']}", []);

        if ($purpose === []) {
            throw new FileError('Unknown file purpose.', 'unknown_purpose');
        }

        $scope = $purpose['scope'] ?? 'tenant';
        if ($scope === 'tenant' && ! $this->context->hasTenant()) {
            throw new FileError('This file must be uploaded inside a training provider.', 'tenant_required');
        }

        /** @var list<string> $mimes */
        $mimes = $purpose['mimes'];
        $maxBytes = ((int) $purpose['max_kb']) * 1024;

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'in:'.implode(',', $mimes)],
            'size' => ['required', 'integer', 'min:1', 'max:'.$maxBytes],
        ], [
            'mime.in' => 'This type of file is not allowed here.',
            'size.max' => 'The file is too large. The limit is '.round($maxBytes / 1048576, 1).' MB.',
        ]);

        if ($validator->fails()) {
            throw new FileError((string) $validator->errors()->first(), 'invalid_file');
        }

        [$store, $integration] = $this->stores->current();
        $id = (string) Str::ulid();
        $tenantId = $this->context->tenantId();

        $file = File::query()->create([
            'id' => $id,
            'tenant_id' => $tenantId,
            'owner_id' => $ownerId,
            'purpose' => $input['purpose'],
            'original_name' => $this->cleanName($input['name']),
            'mime' => $input['mime'],
            'size' => $input['size'],
            'store_driver' => $store->driver(),
            'integration_id' => $integration?->id,
            // Never the uploaded name in the path: no traversal, no guessing.
            'path' => sprintf('%s/%s/%s/%s', $tenantId ?? 'platform', $input['purpose'], now()->format('Y/m'), strtolower($id)),
            'status' => FileStatus::Pending,
        ]);

        $upload = $store->uploadTarget($file->path, $file->mime, $file->size, now()->addMinutes((int) config('files.upload_ttl')));

        return ['file' => $file, 'upload' => $upload];
    }

    /**
     * Local storage: take the uploaded bytes, refusing anything over the declared size.
     *
     * @param  resource  $body
     */
    public function receive(File $file, $body): void
    {
        $this->assertPending($file);

        $temp = tmpfile();
        if ($temp === false) {
            throw new FileError('Could not take the upload.', 'upload_failed', 500);
        }

        try {
            $written = 0;
            while (! feof($body)) {
                $chunk = (string) fread($body, 65536);
                $written += strlen($chunk);

                if ($written > $file->size) {
                    throw new FileError('The file is larger than declared.', 'size_mismatch');
                }

                fwrite($temp, $chunk);
            }

            if ($written !== $file->size) {
                throw new FileError('The upload is incomplete.', 'size_mismatch');
            }

            rewind($temp);
            $this->stores->forFile($file)->disk()->writeStream($file->path, $temp);
        } finally {
            fclose($temp);
        }
    }

    /** The client says the upload finished: check it arrived whole, then process. */
    public function complete(File $file): File
    {
        $this->assertPending($file);
        $disk = $this->stores->forFile($file)->disk();

        if (! $disk->exists($file->path)) {
            throw new FileError('The file has not arrived yet. Upload it, then try again.', 'not_uploaded');
        }

        if ($disk->size($file->path) !== $file->size) {
            $disk->delete($file->path);
            $file->forceFill(['status' => FileStatus::Rejected, 'status_reason' => 'Size differs from the one declared.'])->save();

            throw new FileError('The uploaded file is not the size that was declared.', 'size_mismatch');
        }

        $file->forceFill(['status' => FileStatus::Processing, 'uploaded_at' => now()])->save();
        ProcessFile::dispatch($file->id);

        return $file;
    }

    /** @return array{url: string|null, via: string} */
    public function download(File $file): array
    {
        if (! $file->isReady()) {
            throw new FileError('This file is not available yet.', 'not_ready', 409);
        }

        $url = $this->stores->forFile($file)->downloadUrl(
            $file->path,
            $file->original_name,
            $file->mime,
            now()->addMinutes((int) config('files.download_ttl')),
        );

        $this->audit->record('file.downloaded', $file);

        return $url === null ? ['url' => null, 'via' => 'api'] : ['url' => $url, 'via' => 'direct'];
    }

    public function delete(File $file): void
    {
        $disk = $this->stores->forFile($file)->disk();
        $paths = [$file->path, ...array_column($file->variants ?? [], 'path')];

        foreach ($paths as $path) {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }

        $file->delete();
    }

    /** @return array<string, mixed> */
    public function present(File $file): array
    {
        return [
            'id' => $file->id,
            'purpose' => $file->purpose,
            'name' => $file->original_name,
            'mime' => $file->mime,
            'size' => $file->size,
            'status' => $file->status->value,
            'status_reason' => $file->status_reason,
            'variants' => collect($file->variants ?? [])->map(fn (array $v): array => ['width' => $v['width'], 'height' => $v['height']])->all() ?: (object) [],
            'created_at' => $file->created_at->toIso8601String(),
        ];
    }

    private function assertPending(File $file): void
    {
        if ($file->status !== FileStatus::Pending) {
            throw new FileError('This upload has already been completed.', 'not_pending', 409);
        }
    }

    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        return mb_substr(trim($name), 0, 255) ?: 'file';
    }
}
