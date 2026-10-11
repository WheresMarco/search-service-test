<?php

declare(strict_types=1);

namespace App\Tests;

use App\Booking\BookingFailed;
use App\Booking\BookingService;
use App\Data\BookingStore;
use App\Data\DataRepository;
use App\Domain\Booking;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingServiceTest extends TestCase
{
    private BookingStore $store;
    private BookingService $service;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $data = DataRepository::fromFile(__DIR__ . '/../data.json');
        $this->store = new BookingStore(new PDO('sqlite::memory:'));
        $this->store->seedOnce($data->bookings);
        $this->service = new BookingService($data, $this->store);
        $this->now = new DateTimeImmutable('2026-10-11T12:00:00+02:00');
    }

    private function book(int $venue, int $service, int $staff, string $start): Booking
    {
        return $this->service->book($venue, $service, $staff, new DateTimeImmutable($start), 'Marco', $this->now);
    }

    public function testBooksAFreeSlot(): void
    {
        $booking = $this->book(1, 101, 1001, '2026-10-23T09:00:00+02:00');

        self::assertSame(1001, $booking->staffId);
        self::assertSame('Marco', $booking->customerName);
        self::assertSame('2026-10-23T10:00:00+02:00', $booking->end->format(DATE_ATOM));
        self::assertStringStartsWith('bk_', $booking->id);
    }

    public function testAcceptsAnyOffsetForTheSameInstant(): void
    {
        $booking = $this->book(1, 101, 1001, '2026-10-23T07:00:00Z');

        self::assertSame('2026-10-23T09:00:00+02:00', $booking->start->format(DATE_ATOM));
    }

    public function testSameSlotCannotBeBookedTwice(): void
    {
        $this->book(1, 101, 1001, '2026-10-23T09:00:00+02:00');

        $this->assertFails(BookingFailed::TAKEN, fn () => $this->book(1, 101, 1001, '2026-10-23T09:00:00+02:00'));
    }

    public function testOverlappingButDifferentServiceIsAlsoTaken(): void
    {
        $this->book(1, 101, 1001, '2026-10-23T09:00:00+02:00');

        // 45 min Massage at 09:15 overlaps the 09:00-10:00 booking for the same person.
        $this->assertFails(BookingFailed::TAKEN, fn () => $this->book(1, 102, 1001, '2026-10-23T09:15:00+02:00'));
    }

    public function testCancelledBookingSlotIsBookable(): void
    {
        // b3 (Erik, 2026-10-24 10:00) is cancelled in data.json.
        $booking = $this->book(1, 102, 1002, '2026-10-24T10:00:00+02:00');

        self::assertSame(1002, $booking->staffId);
    }

    public static function failures(): array
    {
        return [
            'existing booking b2' => [1, 101, 1001, '2026-10-23T11:00:00+02:00', BookingFailed::TAKEN],
            'existing UTC booking b6' => [3, 301, 3001, '2026-10-26T10:00:00+01:00', BookingFailed::TAKEN],
            'runs into break' => [1, 101, 1001, '2026-10-23T12:00:00+02:00', BookingFailed::NOT_OFFERED],
            'off the 15-minute grid' => [1, 101, 1001, '2026-10-23T09:05:00+02:00', BookingFailed::NOT_OFFERED],
            'staff not working that day' => [1, 101, 1001, '2026-10-24T10:00:00+02:00', BookingFailed::NOT_OFFERED],
            'ends after venue closes' => [1, 102, 1002, '2026-10-24T14:30:00+02:00', BookingFailed::NOT_OFFERED],
            'staff does not do service' => [3, 302, 3001, '2026-10-26T15:00:00+01:00', BookingFailed::NOT_OFFERED],
            'in the past' => [1, 101, 1001, '2026-10-05T09:00:00+02:00', BookingFailed::IN_PAST],
            'unknown venue' => [99, 101, 1001, '2026-10-23T09:00:00+02:00', BookingFailed::NOT_FOUND],
            'service from another venue' => [1, 201, 1001, '2026-10-23T09:00:00+02:00', BookingFailed::NOT_FOUND],
            'staff from another venue' => [1, 101, 2001, '2026-10-23T09:00:00+02:00', BookingFailed::NOT_FOUND],
        ];
    }

    #[DataProvider('failures')]
    public function testRejects(int $venue, int $service, int $staff, string $start, string $reason): void
    {
        $this->assertFails($reason, fn () => $this->book($venue, $service, $staff, $start));
    }

    public function testStoreRefusesOverlapEvenIfCallerSkippedTheCheck(): void
    {
        // Simulates the race: a booking that passed validation but lost to a concurrent insert.
        $late = new Booking('x', 1001, 101, new DateTimeImmutable('2026-10-23T10:30:00+02:00'), new DateTimeImmutable('2026-10-23T11:30:00+02:00'), Booking::CONFIRMED, 'Late');

        self::assertFalse($this->store->insertIfFree($late));
    }

    public function testSeedingIsIdempotent(): void
    {
        $data = DataRepository::fromFile(__DIR__ . '/../data.json');
        $this->store->seedOnce($data->bookings);

        $all = $this->store->confirmedBetween(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2027-01-01'));
        self::assertCount(6, $all, '7 bookings in data.json, 1 cancelled, none duplicated');
    }

    private function assertFails(string $reason, callable $fn): void
    {
        try {
            $fn();
            self::fail("Expected booking to fail with '$reason'");
        } catch (BookingFailed $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
        }
    }
}
