<?php

declare(strict_types=1);

namespace Modules\Files\Scanning;

/**
 * Accepts everything. Only bound in local development and tests
 * (FILES_SCANNER=none); anywhere else the factory refuses it.
 */
final class NoScanner implements Scanner
{
    public function scan($stream): ScanResult
    {
        return ScanResult::clean();
    }
}
