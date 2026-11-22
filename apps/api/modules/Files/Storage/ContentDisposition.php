<?php

declare(strict_types=1);

namespace Modules\Files\Storage;

use Illuminate\Support\Str;

final class ContentDisposition
{
    /** RFC 6266 attachment header with an ASCII fallback and the UTF-8 name. */
    public static function attachment(string $filename): string
    {
        $ascii = Str::ascii($filename);
        $ascii = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $ascii) ?: 'file';

        return 'attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($filename);
    }
}
