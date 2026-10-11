<?php

declare(strict_types=1);

namespace App\Tests;

use App\Search\Distance;
use PHPUnit\Framework\TestCase;

final class DistanceTest extends TestCase
{
    public function testSamePointIsZero(): void
    {
        self::assertSame(0.0, Distance::km(59.3293, 18.0686, 59.3293, 18.0686));
    }

    public function testStockholmToGothenburg(): void
    {
        // Stockholm Centralstation -> Göteborg Centralstation is ~398 km as the crow flies.
        self::assertEqualsWithDelta(398.0, Distance::km(59.3300, 18.0590, 57.7089, 11.9730), 3.0);
    }

    public function testShortDistanceBetweenVenues(): void
    {
        // Salongens Massage -> Klippotek in data.json, roughly 1.4 km apart.
        self::assertEqualsWithDelta(1.4, Distance::km(59.3166428, 18.0561183, 59.315887, 18.0811638), 0.1);
    }
}
