<?php

declare(strict_types=1);

namespace Modules\Files;

use Modules\Files\Models\File;
use Modules\Files\Scanning\ClamdScanner;
use Modules\Files\Scanning\NoScanner;
use Modules\Files\Scanning\Scanner;
use Modules\Files\Scanning\ScannerUnavailable;
use Modules\Files\Services\ImageVariants;
use Modules\Files\Storage\StorageTester;
use Modules\Settings\Exceptions\SettingsError;
use Modules\Settings\Models\Integration;
use Modules\Settings\Services\IntegrationTesters;
use Modules\Support\ModuleServiceProvider;
use Modules\Tenancy\Context\TenantContext;

/** Files: storage drivers, uploads, virus scanning and image variants. */
final class FilesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Scanner::class, function (): Scanner {
            /** @var array{driver: string, host: string, port: int, timeout: int} $config */
            $config = config('files.scanner');

            if ($config['driver'] === 'none') {
                if (! $this->app->environment(['local', 'testing'])) {
                    throw new ScannerUnavailable('FILES_SCANNER=none is only allowed in local development.');
                }

                return new NoScanner;
            }

            return ClamdScanner::tcp($config['host'], $config['port'], $config['timeout']);
        });

        $this->app->bind(ImageVariants::class, fn (): ImageVariants => new ImageVariants((int) config('files.max_image_pixels')));
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        $testers = $this->app->make(IntegrationTesters::class);
        foreach (['storage.local', 'storage.s3', 'storage.azure'] as $driver) {
            $testers->register($driver, StorageTester::class);
        }

        // A store that still holds files cannot be removed (they would be lost).
        Integration::deleting(function (Integration $integration): void {
            if ($integration->type !== 'storage') {
                return;
            }

            $count = $this->app->make(TenantContext::class)->runAsPlatform(
                fn (): int => File::query()->where('integration_id', $integration->id)->count(),
            );

            if ($count > 0) {
                throw new SettingsError("This store holds {$count} files, so it cannot be removed. Switch it off instead.", 'store_in_use');
            }
        });
    }
}
