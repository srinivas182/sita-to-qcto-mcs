<?php

declare(strict_types=1);

namespace Modules\Tenancy\Domains\CustomHostnames;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Tenancy\Enums\SslStatus;

/** Local and test provider: logs calls and reports certificates as active. */
final class LogCustomHostnameProvider implements CustomHostnameProvider
{
    public function register(string $hostname): string
    {
        $reference = 'log-'.Str::ulid();
        Log::info('custom-hostname.register', ['hostname' => $hostname, 'reference' => $reference]);

        return $reference;
    }

    public function status(string $hostname, string $reference): HostnameStatus
    {
        return new HostnameStatus(SslStatus::Active);
    }

    public function remove(string $reference): void
    {
        Log::info('custom-hostname.remove', ['reference' => $reference]);
    }
}
