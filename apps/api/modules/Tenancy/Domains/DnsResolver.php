<?php

declare(strict_types=1);

namespace Modules\Tenancy\Domains;

interface DnsResolver
{
    /**
     * Look up records of one type. Returns normalised values (lower-case hostnames
     * without trailing dots, TXT strings joined, IPs) or an empty list.
     *
     * @param  'CNAME'|'TXT'|'A'|'AAAA'  $type
     * @return list<string>
     */
    public function lookup(string $name, string $type): array;
}
