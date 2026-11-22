<?php

declare(strict_types=1);

namespace Modules\Tenancy\Domains;

/** DNS lookups through the system resolver (dns_get_record). */
final class NativeDnsResolver implements DnsResolver
{
    public function lookup(string $name, string $type): array
    {
        $constant = match ($type) {
            'CNAME' => DNS_CNAME,
            'TXT' => DNS_TXT,
            'A' => DNS_A,
            'AAAA' => DNS_AAAA,
        };

        $records = @dns_get_record($name, $constant);

        if (! is_array($records)) {
            return [];
        }

        $values = [];

        foreach ($records as $record) {
            $value = match ($type) {
                'CNAME' => $record['target'] ?? null,
                'TXT' => isset($record['entries']) && is_array($record['entries']) ? implode('', $record['entries']) : ($record['txt'] ?? null),
                'A' => $record['ip'] ?? null,
                'AAAA' => $record['ipv6'] ?? null,
            };

            if (is_string($value) && $value !== '') {
                $values[] = $type === 'TXT' ? trim($value) : rtrim(strtolower($value), '.');
            }
        }

        return array_values(array_unique($values));
    }
}
