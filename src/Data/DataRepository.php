<?php

declare(strict_types=1);

namespace App\Data;

use App\Domain\Booking;
use App\Domain\Service;
use App\Domain\Staff;
use App\Domain\Venue;
use DateTimeImmutable;
use RuntimeException;

/**
 * Read-only view of data.json. The file is treated as production data and is
 * never written to; new bookings live in BookingStore.
 */
final class DataRepository
{
    /**
     * @param Venue[] $venues
     * @param Booking[] $bookings bookings shipped in data.json (used to seed the store)
     */
    public function __construct(
        public readonly array $venues,
        public readonly array $bookings,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read data file: $path");
        }

        return self::fromArray(json_decode($raw, true, flags: JSON_THROW_ON_ERROR));
    }

    public static function fromArray(array $data): self
    {
        $venues = [];
        foreach ($data['venues'] ?? [] as $v) {
            $venues[] = new Venue(
                id: (int) $v['id'],
                name: (string) $v['name'],
                lat: isset($v['position']['lat']) ? (float) $v['position']['lat'] : null,
                lng: isset($v['position']['lng']) ? (float) $v['position']['lng'] : null,
                openingHours: $v['openingHours'] ?? [],
                services: array_map(
                    fn (array $s) => new Service((int) $s['id'], (string) $s['name'], (int) $s['durationMinutes'], (int) $s['priceSek']),
                    $v['services'] ?? [],
                ),
                staff: array_map(
                    fn (array $s) => new Staff(
                        (int) $s['id'],
                        (string) $s['name'],
                        array_map('intval', $s['serviceIds'] ?? []),
                        $s['workingHours'] ?? [],
                        $s['breaks'] ?? [],
                    ),
                    $v['staff'] ?? [],
                ),
            );
        }

        $bookings = array_map(
            fn (array $b) => new Booking(
                id: (string) $b['id'],
                staffId: (int) $b['staffId'],
                serviceId: (int) $b['serviceId'],
                // Offsets in the data are mixed (+02:00, +01:00, Z); DateTimeImmutable keeps the instant correct.
                start: new DateTimeImmutable($b['start']),
                end: new DateTimeImmutable($b['end']),
                status: (string) $b['status'],
            ),
            $data['bookings'] ?? [],
        );

        return new self($venues, $bookings);
    }

    public function venue(int $id): ?Venue
    {
        foreach ($this->venues as $venue) {
            if ($venue->id === $id) {
                return $venue;
            }
        }

        return null;
    }

    public function venueOfStaff(int $staffId): ?Venue
    {
        foreach ($this->venues as $venue) {
            if ($venue->staffMember($staffId) !== null) {
                return $venue;
            }
        }

        return null;
    }
}
