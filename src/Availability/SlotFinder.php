<?php

declare(strict_types=1);

namespace App\Availability;

use App\Domain\Booking;
use App\Domain\Clock;
use App\Domain\Service;
use App\Domain\Staff;
use App\Domain\Venue;
use DateTimeImmutable;

/**
 * Free slots for one staff member and one service:
 *
 *   window = staff working hours ∩ venue opening hours   (per local day)
 *   busy   = breaks ∪ confirmed bookings                 (overlaps in the data are fine)
 *   slot   = [t, t + duration) inside window, not overlapping busy, t on a 15-minute grid, t in the future
 *
 * Pure function of its inputs, so search and booking validation share one source of truth.
 * Arithmetic is done on Unix timestamps: stepping with DateInterval on wall-clock time
 * misbehaves around DST switches (e.g. 2026-10-25 in Stockholm).
 */
final class SlotFinder
{
    public const STEP_MINUTES = 15;

    /**
     * @param DateTimeImmutable $fromDay first local day (inclusive)
     * @param DateTimeImmutable $toDay last local day (inclusive)
     * @param Booking[] $bookings bookings to treat as busy; filtered to this staff member and confirmed status
     * @return Slot[]
     */
    public function find(
        Venue $venue,
        Service $service,
        Staff $staff,
        DateTimeImmutable $fromDay,
        DateTimeImmutable $toDay,
        array $bookings,
        DateTimeImmutable $now,
    ): array {
        if (!$staff->performs($service->id)) {
            return [];
        }

        $bookingIntervals = [];
        foreach ($bookings as $booking) {
            if ($booking->staffId === $staff->id && $booking->isConfirmed()) {
                $bookingIntervals[] = [$booking->start->getTimestamp(), $booking->end->getTimestamp()];
            }
        }

        $duration = $service->durationMinutes * 60;
        $step = self::STEP_MINUTES * 60;
        $nowTs = $now->getTimestamp();
        $last = Clock::at($toDay, '00:00');
        $slots = [];

        for ($day = Clock::at($fromDay, '00:00'); $day <= $last; $day = $day->modify('+1 day')) {
            $weekday = Clock::weekday($day);
            $venueHours = $venue->hoursOn($weekday);
            $staffHours = $staff->hoursOn($weekday);
            if ($venueHours === null || $staffHours === null) {
                continue;
            }

            $windowStart = max(Clock::at($day, $venueHours[0])->getTimestamp(), Clock::at($day, $staffHours[0])->getTimestamp());
            $windowEnd = min(Clock::at($day, $venueHours[1])->getTimestamp(), Clock::at($day, $staffHours[1])->getTimestamp());

            $busy = $bookingIntervals;
            foreach ($staff->breaksOn($weekday) as [$breakStart, $breakEnd]) {
                $busy[] = [Clock::at($day, $breakStart)->getTimestamp(), Clock::at($day, $breakEnd)->getTimestamp()];
            }

            for ($t = $windowStart; $t + $duration <= $windowEnd; $t += $step) {
                if ($t <= $nowTs || self::overlapsAny($t, $t + $duration, $busy)) {
                    continue;
                }
                $slots[] = new Slot($staff, self::fromTimestamp($t), self::fromTimestamp($t + $duration));
            }
        }

        return $slots;
    }

    /** Half-open intervals: a slot ending at 12:00 does not collide with a break starting at 12:00. */
    private static function overlapsAny(int $start, int $end, array $intervals): bool
    {
        foreach ($intervals as [$busyStart, $busyEnd]) {
            if ($start < $busyEnd && $end > $busyStart) {
                return true;
            }
        }

        return false;
    }

    private static function fromTimestamp(int $ts): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $ts))->setTimezone(Clock::tz());
    }
}
