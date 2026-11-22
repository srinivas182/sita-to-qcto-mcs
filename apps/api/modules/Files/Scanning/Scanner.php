<?php

declare(strict_types=1);

namespace Modules\Files\Scanning;

interface Scanner
{
    /**
     * Scan a stream from its current position to the end.
     *
     * @param  resource  $stream
     *
     * @throws ScannerUnavailable when the file could not be scanned
     */
    public function scan($stream): ScanResult;
}
