<?php

declare(strict_types=1);

namespace Modules\Files\Scanning;

final readonly class ScanResult
{
    public function __construct(public bool $clean, public ?string $signature = null) {}

    public static function clean(): self
    {
        return new self(true);
    }

    public static function infected(string $signature): self
    {
        return new self(false, $signature);
    }
}
