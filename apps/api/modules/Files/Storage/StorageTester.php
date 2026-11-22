<?php

declare(strict_types=1);

namespace Modules\Files\Storage;

use Illuminate\Support\Str;
use Modules\Settings\Contracts\IntegrationTester;
use Modules\Settings\Models\Integration;
use Modules\Settings\Services\TestResult;

/** Writes, reads back and deletes a small probe file. */
final class StorageTester implements IntegrationTester
{
    public function __construct(private readonly StoreFactory $stores) {}

    public function test(Integration $integration): TestResult
    {
        $disk = $this->stores->for($integration)->disk();
        $path = '_probe/'.Str::ulid().'.txt';
        $body = 'skills-platform storage check '.now()->toIso8601String();

        $disk->put($path, $body);
        $read = $disk->get($path);
        $disk->delete($path);

        return $read === $body
            ? TestResult::ok('Wrote, read and deleted a test file.')
            : TestResult::failed('The test file could not be read back.');
    }
}
