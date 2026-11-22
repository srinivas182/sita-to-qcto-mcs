<?php

declare(strict_types=1);

namespace Modules\Tenancy\Resolution;

/** Hostname helpers shared by resolution and domain management. */
final class Hostname
{
    /** Lower-case, strip port and trailing dot. */
    public static function normalise(string $host): string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('/:\d+$/', '', $host);

        return rtrim($host, '.');
    }

    /** Valid public DNS hostname with at least two labels (no IPs, no wildcards). */
    public static function isValid(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $host);

        if (count($labels) < 2 || ! preg_match('/^[a-z]{2,63}$/', end($labels))) {
            return false;
        }

        foreach ($labels as $label) {
            if (! preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label)) {
                return false;
            }
        }

        return true;
    }

    public static function baseDomain(): string
    {
        return self::normalise((string) config('platform.base_domain'));
    }

    /** Whether the host is (under) the platform base domain. */
    public static function isUnderBase(string $host): bool
    {
        $base = self::baseDomain();

        return $host === $base || str_ends_with($host, '.'.$base);
    }

    /** Hosts that serve the platform itself (console, API, marketing). */
    public static function isPlatformHost(string $host): bool
    {
        $base = self::baseDomain();

        /** @var list<string> $hosts */
        $hosts = config('platform.platform_hosts', []);

        foreach ($hosts as $name) {
            $name = trim($name);
            $full = $name === '@' ? $base : (str_contains($name, '.') ? $name : $name.'.'.$base);

            if ($host === self::normalise($full)) {
                return true;
            }
        }

        return false;
    }

    public static function subdomainFor(string $code): string
    {
        return strtolower($code).'.'.self::baseDomain();
    }
}
