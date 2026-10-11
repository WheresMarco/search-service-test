<?php

declare(strict_types=1);

namespace App\Tests;

use App\Data\DataIntegrity;
use App\Data\DataRepository;
use PHPUnit\Framework\TestCase;

final class DataIntegrityTest extends TestCase
{
    public function testReportsTheKnownIssuesInDataJson(): void
    {
        $issues = DataIntegrity::check(DataRepository::fromFile(__DIR__ . '/../data.json'));

        self::assertSame([
            'Venue 4 (Studio Norr) has no position; it is excluded from search.',
            "Booking b2: overlaps Anna's break 12:00-13:00.",
            'Bookings b4 and b5 overlap for staff 2001 (double booking).',
        ], $issues);
    }

    public function testCleanDataHasNoIssues(): void
    {
        $data = DataRepository::fromArray(['venues' => [[
            'id' => 1, 'name' => 'V', 'position' => ['lat' => 59.3, 'lng' => 18.0],
            'openingHours' => ['mon' => ['09:00', '17:00']],
            'services' => [['id' => 1, 'name' => 'S', 'durationMinutes' => 60, 'priceSek' => 100]],
            'staff' => [['id' => 1, 'name' => 'A', 'serviceIds' => [1], 'workingHours' => ['mon' => ['09:00', '17:00']], 'breaks' => []]],
        ]], 'bookings' => [
            ['id' => 'x', 'staffId' => 1, 'serviceId' => 1, 'start' => '2026-10-26T09:00:00+01:00', 'end' => '2026-10-26T10:00:00+01:00', 'status' => 'confirmed'],
        ]]);

        self::assertSame([], DataIntegrity::check($data));
    }
}
