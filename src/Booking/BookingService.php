<?php

declare(strict_types=1);

namespace App\Booking;

use App\Availability\Slot;
use App\Availability\SlotFinder;
use App\Data\BookingStore;
use App\Data\DataRepository;
use App\Domain\Booking;
use App\Domain\Clock;
use DateTimeImmutable;

/**
 * Books a slot. The client's slot is never trusted: it is re-validated with the same
 * SlotFinder that search uses, and the final overlap check happens inside a DB transaction.
 */
final class BookingService
{
    public function __construct(
        private readonly DataRepository $data,
        private readonly BookingStore $store,
        private readonly SlotFinder $slotFinder = new SlotFinder(),
    ) {
    }

    /** @throws BookingFailed */
    public function book(
        int $venueId,
        int $serviceId,
        int $staffId,
        DateTimeImmutable $start,
        string $customerName,
        DateTimeImmutable $now,
    ): Booking {
        $venue = $this->data->venue($venueId)
            ?? throw new BookingFailed(BookingFailed::NOT_FOUND, "Venue $venueId does not exist.");
        $service = $venue->service($serviceId)
            ?? throw new BookingFailed(BookingFailed::NOT_FOUND, "Service $serviceId is not offered at venue $venueId.");
        $staff = $venue->staffMember($staffId)
            ?? throw new BookingFailed(BookingFailed::NOT_FOUND, "Staff member $staffId does not work at venue $venueId.");

        if (!$staff->performs($serviceId)) {
            throw new BookingFailed(BookingFailed::NOT_OFFERED, "{$staff->name} does not perform {$service->name}.");
        }
        if ($start <= $now) {
            throw new BookingFailed(BookingFailed::IN_PAST, 'The requested start time is in the past.');
        }

        $day = Clock::local($start);
        $offered = $this->slotFinder->find($venue, $service, $staff, $day, $day, [], $now);
        if (self::match($offered, $start) === null) {
            throw new BookingFailed(
                BookingFailed::NOT_OFFERED,
                'The requested time is not a bookable slot (outside working/opening hours, during a break, or not on the '
                . SlotFinder::STEP_MINUTES . '-minute grid).',
            );
        }

        $dayStart = Clock::at($day, '00:00');
        $existing = $this->store->confirmedBetween($dayStart, $dayStart->modify('+1 day'));
        $slot = self::match($this->slotFinder->find($venue, $service, $staff, $day, $day, $existing, $now), $start)
            ?? throw new BookingFailed(BookingFailed::TAKEN, 'The requested slot is already booked.');

        $booking = new Booking(
            id: 'bk_' . bin2hex(random_bytes(8)),
            staffId: $staffId,
            serviceId: $serviceId,
            start: $slot->start,
            end: $slot->end,
            status: Booking::CONFIRMED,
            customerName: $customerName,
        );

        // Guards against a concurrent request taking the slot between the check above and the insert.
        if (!$this->store->insertIfFree($booking)) {
            throw new BookingFailed(BookingFailed::TAKEN, 'The requested slot was just booked by someone else.');
        }

        return $booking;
    }

    /** @param Slot[] $slots */
    private static function match(array $slots, DateTimeImmutable $start): ?Slot
    {
        foreach ($slots as $slot) {
            if ($slot->start->getTimestamp() === $start->getTimestamp()) {
                return $slot;
            }
        }

        return null;
    }
}
