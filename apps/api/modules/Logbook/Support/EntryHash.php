<?php

declare(strict_types=1);

namespace Modules\Logbook\Support;

/**
 * The fingerprint of a logbook entry, made on the phone when the entry is written (even
 * offline) and checked on the server. Each entry includes the previous entry's hash, so
 * the chain shows if an earlier entry is changed or removed. The phone app has the same
 * function (apps/mobile/src/core/entryHash.ts); the test vectors must match.
 */
final class EntryHash
{
    /** @param  list<string>  $photoHashes */
    public static function make(?string $prev, string $clientId, string $moduleId, string $activity, string $date, int $minutes, ?string $note, array $photoHashes, string $deviceTime): string
    {
        $photos = array_map('strtolower', $photoHashes);
        sort($photos);
        $text = str_replace(["\r\n", "\r", '|'], ["\n", "\n", '/'], trim((string) $note));

        return hash('sha256', implode('|', ['v1', $prev ?? '', strtolower($clientId), strtolower($moduleId), $activity, $date, (string) $minutes, $text, implode(',', $photos), $deviceTime]));
    }
}
