<?php

declare(strict_types=1);

namespace Modules\Files\Storage;

use InvalidArgumentException;
use Modules\Files\Exceptions\FileError;
use Modules\Files\Models\File;
use Modules\Settings\Models\Integration;
use Modules\Tenancy\Context\TenantContext;

/**
 * Builds the store for new files (the storage integration the Super Admin switched
 * on, or the default) and for existing files (the store each one was written to).
 */
final class StoreFactory
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array{0: FileStore, 1: Integration|null} store for new files */
    public function current(): array
    {
        $integration = $this->context->runAsPlatform(
            fn () => Integration::query()->active('storage')->first(),
        );

        if ($integration === null) {
            /** @var array{driver: string, root: string} $default */
            $default = config('files.default');

            return [new LocalStore($default['root']), null];
        }

        return [$this->for($integration), $integration];
    }

    public function forFile(File $file): FileStore
    {
        if ($file->integration_id === null) {
            /** @var array{driver: string, root: string} $default */
            $default = config('files.default');

            return new LocalStore($default['root']);
        }

        $integration = $this->context->runAsPlatform(fn () => Integration::query()->find($file->integration_id));

        if ($integration === null) {
            throw new FileError('The storage this file was saved to has been removed.', 'store_missing', 410);
        }

        return $this->for($integration);
    }

    public function for(Integration $integration): FileStore
    {
        $values = $integration->values();

        return match ($integration->driver) {
            'storage.local' => new LocalStore((string) ($values['root'] ?? 'files')),
            'storage.s3' => new S3Store($values),
            'storage.azure' => new AzureStore($values),
            default => throw new InvalidArgumentException("Not a storage driver: {$integration->driver}"),
        };
    }
}
