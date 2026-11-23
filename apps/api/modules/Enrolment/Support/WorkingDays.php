<?php

declare(strict_types=1);

namespace Modules\Enrolment\Support;

use Carbon\CarbonImmutable;

/** South African working days: weekdays that are not public holidays (Public Holidays Act, with the Sunday rule). */
final class WorkingDays
{
    /** @return list<string> Y-m-d dates of the public holidays in a year */
    public static function holidays(int $year): array
    {
        $easter = self::easter($year);
        $days = [];
        foreach (['01-01', '03-21', '04-27', '05-01', '06-16', '08-09', '09-24', '12-16', '12-25', '12-26'] as $md) {
            $d = CarbonImmutable::parse("{$year}-{$md}");
            $days[] = $d->toDateString();
            if ($d->isSunday()) {
                $days[] = $d->addDay()->toDateString(); // a holiday on a Sunday moves to the Monday
            }
        }
        $days[] = $easter->subDays(2)->toDateString(); // Good Friday
        $days[] = $easter->addDay()->toDateString();   // Family Day

        return array_values(array_unique($days));
    }

    /** Easter Sunday (anonymous Gregorian algorithm), without the calendar extension. */
    public static function easter(int $y): CarbonImmutable
    {
        $a = $y % 19;
        $b = intdiv($y, 100);
        $c = $y % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::parse(sprintf('%04d-%02d-%02d', $y, $month, $day));
    }

    public static function isWorkingDay(CarbonImmutable $day): bool
    {
        return ! $day->isWeekend() && ! in_array($day->toDateString(), self::holidays($day->year), true);
    }

    /** The day that is $n working days after $start ($start itself is day 0). */
    public static function add(CarbonImmutable $start, int $n): CarbonImmutable
    {
        $day = $start;
        while ($n > 0) {
            $day = $day->addDay();
            if (self::isWorkingDay($day)) {
                $n--;
            }
        }

        return $day;
    }
}
