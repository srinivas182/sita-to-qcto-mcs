<?php

declare(strict_types=1);

namespace Modules\Files\Support;

enum FileStatus: string
{
    /** Record created; waiting for the bytes. */
    case Pending = 'pending';
    /** Uploaded; being checked (type, virus scan, variants). */
    case Processing = 'processing';
    /** Clean and usable. */
    case Ready = 'ready';
    /** Not accepted: a virus, or the content is not what it claimed to be. */
    case Rejected = 'rejected';
    /** Could not be checked (scanner or store unavailable). Retry is possible. */
    case Failed = 'failed';
}
