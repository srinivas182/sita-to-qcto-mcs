<?php

declare(strict_types=1);

namespace Modules\Tenancy\Domains\CustomHostnames;

/**
 * Edge provider that terminates TLS for tenants' own domains (ADR-005: Cloudflare
 * for SaaS custom hostnames). Subdomains are covered by the platform wildcard and
 * never go through this provider.
 */
interface CustomHostnameProvider
{
    /** Register the hostname at the edge; returns the provider's reference. */
    public function register(string $hostname): string;

    public function status(string $hostname, string $reference): HostnameStatus;

    public function remove(string $reference): void;
}
