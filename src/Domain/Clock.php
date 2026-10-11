<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The data has no timezone information; all venues are in Stockholm, so local
 * wall-clock times (opening hours, breaks) are interpreted in Europe/Stockholm.
 */
final class Clock
{
    public const TIMEZONE = 'Europe/Stockholm';

    public static function tz(): DateTimeZone
    {
        static $tz = null;

        return $tz ??= new DateTimeZone(self::TIMEZONE);
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::tz());
    }

    /** Converts any instant to local time, for output. */
    public static function local(DateTimeImmutable $t): DateTimeImmutable
    {
        return $t->setTimezone(self::tz());
    }

    /** "mon".."sun" for a local date. */
    public static function weekday(DateTimeImmutable $day): string
    {
        return strtolower(self::local($day)->format('D'));
    }

    /** Local wall-clock time "HH:MM" on the given local date, as an instant. */
    public static function at(DateTimeImmutable $day, string $hhmm): DateTimeImmutable
    {
        return new DateTimeImmutable(self::local($day)->format('Y-m-d') . ' ' . $hhmm, self::tz());
    }
}
