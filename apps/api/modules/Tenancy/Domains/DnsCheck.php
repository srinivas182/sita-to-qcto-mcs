<?php

declare(strict_types=1);

namespace Modules\Tenancy\Domains;

use JsonSerializable;

/**
 * Outcome of a live DNS check, shaped for the domain set-up screen: each record the
 * provider must create, what we expect, what we found, and whether it is correct.
 */
final readonly class DnsCheck implements JsonSerializable
{
    /**
     * @param  list<array{type: string, name: string, expected: string, found: list<string>, ok: bool}>  $records
     */
    public function __construct(
        public array $records,
    ) {}

    public function passed(): bool
    {
        foreach ($this->records as $record) {
            if (! $record['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @return array{passed: bool, records: list<array{type: string, name: string, expected: string, found: list<string>, ok: bool}>} */
    public function jsonSerialize(): array
    {
        return ['passed' => $this->passed(), 'records' => $this->records];
    }
}
