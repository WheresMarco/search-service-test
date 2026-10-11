<?php

declare(strict_types=1);

namespace App\Tests;

use App\Availability\Slot;
use App\Availability\SlotFinder;
use App\Data\DataRepository;
use App\Domain\Clock;
use App\Domain\Service;
use App\Domain\Staff;
use App\Domain\Venue;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Uses the real data.json: the traps in it (overlaps, cancelled bookings, mixed offsets,
 * the DST switch on 2026-10-25) are exactly what these tests are about.
 */
final class SlotFinderTest extends TestCase
{
    private DataRepository $repo;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->repo = DataRepository::fromFile(__DIR__ . '/../data.json');
        $this->now = new DateTimeImmutable('2026-10-11T12:00:00+02:00');
    }

    /** @return string[] local "H:i" start times */
    private function startTimes(int $venueId, int $serviceId, int $staffId, string $day, ?DateTimeImmutable $now = null): array
    {
        $venue = $this->repo->venue($venueId);
        $slots = (new SlotFinder())->find(
            $venue,
            $venue->service($serviceId),
            $venue->staffMember($staffId),
            new DateTimeImmutable($day, Clock::tz()),
            new DateTimeImmutable($day, Clock::tz()),
            $this->repo->bookings,
            $now ?? $this->now,
        );

        return array_map(fn (Slot $s) => $s->start->format('H:i'), $slots);
    }

    public function testBookingsAndBreakAreExcluded(): void
    {
        // Anna, Fri 2026-10-23: works 09-17, break 12-13, booked 10:00-11:00 and 11:00-12:30 (b2 runs into the break).
        $times = $this->startTimes(1, 101, 1001, '2026-10-23');

        self::assertSame('09:00', $times[0]);
        self::assertSame('13:00', $times[1], 'nothing between 09:00 and the end of the break');
        self::assertSame('16:00', end($times), '60 min service must end by 17:00');
        self::assertCount(14, $times);
    }

    public function testCancelledBookingDoesNotBlockAndVenueHoursCapStaffHours(): void
    {
        // Erik, Sat 2026-10-24: works 10-18 but the venue closes at 15. b3 (10:00-10:45) is cancelled.
        $times = $this->startTimes(1, 102, 1002, '2026-10-24');

        self::assertSame('10:00', $times[0]);
        self::assertSame('14:15', end($times), '45 min service ends exactly at closing time');
    }

    public function testOverlappingBookingsInDataAreBothBusy(): void
    {
        // Sara, Sun 2026-10-25 (DST switch day): b4 08:00-08:50 and b5 08:30-09:20 overlap in the source data.
        $venue = $this->repo->venue(2);
        $slots = (new SlotFinder())->find(
            $venue, $venue->service(201), $venue->staffMember(2001),
            new DateTimeImmutable('2026-10-25', Clock::tz()), new DateTimeImmutable('2026-10-25', Clock::tz()),
            $this->repo->bookings, $this->now,
        );

        self::assertSame('2026-10-25T09:30:00+01:00', $slots[0]->toArray()['start'], 'first free slot after both bookings, in CET');
        self::assertSame('19:00', end($slots)->start->format('H:i'));
    }

    public function testUtcBookingBlocksTheRightLocalTime(): void
    {
        // Johan, Mon 2026-10-26: b6 is 09:00Z-09:30Z = 10:00-10:30 local.
        $times = $this->startTimes(3, 301, 3001, '2026-10-26');

        self::assertSame(['09:00', '09:15', '09:30', '10:30'], array_slice($times, 0, 4));
    }

    public function testStaffWithoutTheServiceGetsNoSlots(): void
    {
        // Johan does not do Damklippning (302).
        self::assertSame([], $this->startTimes(3, 302, 3001, '2026-10-26'));
    }

    public function testClosedDaysYieldNoSlots(): void
    {
        self::assertSame([], $this->startTimes(1, 101, 1001, '2026-10-25'), 'venue closed on Sunday (null)');
        self::assertSame([], $this->startTimes(1, 101, 1001, '2026-10-24'), 'Anna does not work Saturdays (missing key)');
    }

    public function testPastSlotsAreNotOffered(): void
    {
        $now = new DateTimeImmutable('2026-10-23T09:30:00+02:00');

        self::assertSame('13:00', $this->startTimes(1, 101, 1001, '2026-10-23', $now)[0]);
    }

    public function testDstFallBackDayHasAnExtraHourOfSlots(): void
    {
        // On 2026-10-25 clocks go from 03:00 CEST back to 02:00 CET, so 00:00-06:00 local is 7 real hours.
        $service = new Service(1, 'Night shift', 60, 100);
        $staff = new Staff(1, 'Nils', [1], ['sun' => ['00:00', '06:00']], []);
        $venue = new Venue(1, 'Open late', 59.3, 18.0, ['sun' => ['00:00', '06:00']], [$service], [$staff]);
        $day = new DateTimeImmutable('2026-10-25', Clock::tz());

        $slots = (new SlotFinder())->find($venue, $service, $staff, $day, $day, [], $this->now);
        $starts = array_map(fn (Slot $s) => $s->toArray()['start'], $slots);

        self::assertCount(25, $slots, '(7h - 1h) / 15min + 1');
        self::assertContains('2026-10-25T02:30:00+02:00', $starts);
        self::assertContains('2026-10-25T02:30:00+01:00', $starts, '02:30 happens twice');
        self::assertSame('2026-10-25T06:00:00+01:00', end($slots)->toArray()['end']);
    }

    public function testMultiDayRange(): void
    {
        $venue = $this->repo->venue(1);
        $slots = (new SlotFinder())->find(
            $venue, $venue->service(101), $venue->staffMember(1001),
            new DateTimeImmutable('2026-10-23', Clock::tz()), new DateTimeImmutable('2026-10-26', Clock::tz()),
            $this->repo->bookings, $this->now,
        );
        $days = array_values(array_unique(array_map(fn (Slot $s) => $s->start->format('Y-m-d'), $slots)));

        self::assertSame(['2026-10-23', '2026-10-26'], $days, 'Anna is off on the weekend');
    }
}
