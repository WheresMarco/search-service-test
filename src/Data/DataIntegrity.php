<?php

declare(strict_types=1);

namespace App\Data;

use App\Domain\Booking;
use App\Domain\Clock;

/**
 * Reports inconsistencies in data.json. The service tolerates all of these (it never crashes on
 * them and never offers a slot that collides with them), but in production they should be raised
 * with whoever owns the data rather than silently "fixed".
 */
final class DataIntegrity
{
    /** @return list<string> */
    public static function check(DataRepository $data): array
    {
        $issues = [];

        foreach ($data->venues as $venue) {
            if (!$venue->hasPosition()) {
                $issues[] = "Venue {$venue->id} ({$venue->name}) has no position; it is excluded from search.";
            }
        }

        $confirmed = array_values(array_filter($data->bookings, fn (Booking $b) => $b->isConfirmed()));
        foreach ($confirmed as $booking) {
            $venue = $data->venueOfStaff($booking->staffId);
            if ($venue === null) {
                $issues[] = "Booking {$booking->id} references unknown staff {$booking->staffId}.";
                continue;
            }
            $staff = $venue->staffMember($booking->staffId);
            $service = $venue->service($booking->serviceId);
            if ($service === null || !$staff->performs($booking->serviceId)) {
                $issues[] = "Booking {$booking->id}: {$staff->name} does not perform service {$booking->serviceId}.";
            } elseif (($booking->end->getTimestamp() - $booking->start->getTimestamp()) !== $service->durationMinutes * 60) {
                $issues[] = "Booking {$booking->id}: length differs from {$service->name} ({$service->durationMinutes} min).";
            }

            $weekday = Clock::weekday($booking->start);
            $start = Clock::local($booking->start)->format('H:i');
            $end = Clock::local($booking->end)->format('H:i');
            $hours = $staff->hoursOn($weekday);
            if ($hours === null || $start < $hours[0] || $end > $hours[1]) {
                $issues[] = "Booking {$booking->id}: outside {$staff->name}'s working hours.";
            }
            foreach ($staff->breaksOn($weekday) as [$breakStart, $breakEnd]) {
                if ($start < $breakEnd && $end > $breakStart) {
                    $issues[] = "Booking {$booking->id}: overlaps {$staff->name}'s break $breakStart-$breakEnd.";
                }
            }
        }

        foreach ($confirmed as $i => $a) {
            foreach (array_slice($confirmed, $i + 1) as $b) {
                if ($a->staffId === $b->staffId && $a->start < $b->end && $a->end > $b->start) {
                    $issues[] = "Bookings {$a->id} and {$b->id} overlap for staff {$a->staffId} (double booking).";
                }
            }
        }

        return $issues;
    }
}
