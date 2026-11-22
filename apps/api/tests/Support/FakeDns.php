<?php

declare(strict_types=1);

namespace Tests\Support;

use Modules\Tenancy\Domains\DnsResolver;

/** In-memory DNS for tests: set(name, type, values). */
final class FakeDns implements DnsResolver
{
    /** @var array<string, list<string>> */
    private array $records = [];

    public int $lookups = 0;

    /** @param  list<string>  $values */
    public function set(string $name, string $type, array $values): self
    {
        $this->records[strtolower($name).'|'.$type] = $values;

        return $this;
    }

    public function lookup(string $name, string $type): array
    {
        $this->lookups++;

        return $this->records[strtolower($name).'|'.$type] ?? [];
    }
}
