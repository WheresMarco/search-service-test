<?php

declare(strict_types=1);

namespace App\Tests;

use App\Data\DataRepository;
use PHPUnit\Framework\TestCase;

final class DataRepositoryTest extends TestCase
{
    public function testLoadsShippedDataFile(): void
    {
        $repo = DataRepository::fromFile(__DIR__ . '/../data.json');

        self::assertCount(4, $repo->venues);
        self::assertCount(7, $repo->bookings);
        self::assertFalse($repo->venue(4)->hasPosition(), 'Studio Norr has position: null');
        self::assertNull($repo->venue(1)->hoursOn('sun'), 'null opening hours means closed');
        self::assertNull($repo->venue(1)->staffMember(1001)->hoursOn('sat'), 'missing key means closed');
        self::assertSame(1, $repo->venueOfStaff(1002)->id);
    }

    public function testUtcBookingIsConvertedToStockholmTime(): void
    {
        $repo = DataRepository::fromFile(__DIR__ . '/../data.json');
        $b6 = $repo->bookings[5];

        self::assertSame('b6', $b6->id);
        // 09:00Z on 2026-10-26 is 10:00 in Stockholm (CET, after the DST switch).
        self::assertSame('2026-10-26T10:00:00+01:00', $b6->start->setTimezone(new \DateTimeZone('Europe/Stockholm'))->format(DATE_ATOM));
    }
}
