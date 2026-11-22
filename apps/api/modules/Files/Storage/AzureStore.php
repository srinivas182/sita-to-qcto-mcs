<?php

declare(strict_types=1);

namespace Modules\Files\Storage;

use AzureOss\Storage\Blob\BlobServiceClient;
use AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Config;
use League\Flysystem\Filesystem as Flysystem;

/** Azure Blob Storage (South Africa North recommended). */
final class AzureStore implements FileStore
{
    private ?AzureBlobStorageAdapter $adapter = null;

    private ?FilesystemAdapter $disk = null;

    /** @param  array<string, mixed>  $values */
    public function __construct(private readonly array $values) {}

    public function driver(): string
    {
        return 'storage.azure';
    }

    public function disk(): Filesystem
    {
        if ($this->disk === null) {
            $adapter = $this->adapter();
            $config = ['throw' => true];
            $this->disk = new FilesystemAdapter(new Flysystem($adapter, $config), $adapter, $config);
        }

        return $this->disk;
    }

    public function uploadTarget(string $path, string $mime, int $size, DateTimeInterface $expires): UploadTarget
    {
        // Create + write only, for this one blob.
        $url = $this->adapter()->temporaryUrl($path, $expires, new Config(['permissions' => 'cw']));

        return new UploadTarget('PUT', $url, ['x-ms-blob-type' => 'BlockBlob', 'Content-Type' => $mime]);
    }

    public function downloadUrl(string $path, string $filename, string $mime, DateTimeInterface $expires): string
    {
        return $this->adapter()->temporaryUrl($path, $expires, new Config([
            'permissions' => 'r',
            'httpHeaders' => ['contentDisposition' => ContentDisposition::attachment($filename), 'contentType' => $mime],
        ]));
    }

    private function adapter(): AzureBlobStorageAdapter
    {
        if ($this->adapter === null) {
            $account = (string) ($this->values['account_name'] ?? '');
            $key = (string) ($this->values['account_key'] ?? '');
            $endpoint = (string) ($this->values['endpoint'] ?? '');

            $connection = $endpoint !== ''
                ? "DefaultEndpointsProtocol=https;AccountName={$account};AccountKey={$key};BlobEndpoint={$endpoint}"
                : "DefaultEndpointsProtocol=https;AccountName={$account};AccountKey={$key};EndpointSuffix=core.windows.net";

            $container = BlobServiceClient::fromConnectionString($connection)
                ->getContainerClient((string) ($this->values['container'] ?? ''));

            $this->adapter = new AzureBlobStorageAdapter(
                $container,
                trim((string) ($this->values['prefix'] ?? ''), '/'),
                visibilityHandling: AzureBlobStorageAdapter::ON_VISIBILITY_IGNORE,
            );
        }

        return $this->adapter;
    }
}
