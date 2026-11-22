<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Daily and idempotent: keep the coming months of audit-log partitions in place.
Schedule::command('audit:partitions --months=3')->dailyAt('02:10')->onOneServer();
