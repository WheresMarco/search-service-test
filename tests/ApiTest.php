<?php

declare(strict_types=1);

namespace App\Tests;

use App\Data\BookingStore;
use App\Data\DataRepository;
use App\Http\Api;
use App\Http\Response;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises the HTTP layer end to end (routing, validation, JSON shape) without a running server. */
final class ApiTest extends TestCase
{
    private Api $api;

    protected function setUp(): void
    {
        $data = DataRepository::fromFile(__DIR__ . '/../data.json');
        $store = new BookingStore(new PDO('sqlite::memory:'));
        $store->seedOnce($data->bookings);
        $this->api = new Api($data, $store, fn () => new DateTimeImmutable('2026-10-11T12:00:00+02:00'));
    }

    private function get(string $uri): Response
    {
        return $this->api->handle('GET', $uri);
    }

    private function post(array|string $body): Response
    {
        return $this->api->handle('POST', '/bookings', is_string($body) ? $body : json_encode($body));
    }

    private const SEARCH = '/search?service=massage&lat=59.3293&lng=18.0686&from=2026-10-23&to=2026-10-26';

    public function testSearchReturnsMatchingServicesSortedByDistance(): void
    {
        $res = $this->get(self::SEARCH);

        self::assertSame(200, $res->status);
        $pairs = array_map(fn ($r) => [$r['venue']['id'], $r['service']['name']], $res->body['results']);
        // Venue 1 (~1.6 km) offers three "massage" services; "Massör - 50 min" (venue 2) does not match.
        self::assertSame([[1, 'Klassisk massage'], [1, 'Massage'], [1, 'Djupvävnadsmassage']], $pairs);
        self::assertEqualsWithDelta(1.6, $res->body['results'][0]['distanceKm'], 0.1);
        self::assertSame('Studio Norr', $res->body['excluded'][0]['venue']['name']);
        self::assertSame('missing_position', $res->body['excluded'][0]['reason']);
    }

    public function testSearchSlotShape(): void
    {
        $slot = $this->get(self::SEARCH)->body['results'][0]['slots'][0];

        self::assertSame(['staffId' => 1001, 'staffName' => 'Anna', 'start' => '2026-10-23T09:00:00+02:00', 'end' => '2026-10-23T10:00:00+02:00'], $slot);
    }

    public function testRadiusAndLimit(): void
    {
        $body = $this->get('/search?service=a&lat=59.3293&lng=18.0686&from=2026-10-23&to=2026-10-23&radiusKm=1.7&limit=2')->body;

        self::assertCount(2, $body['results']);
        foreach ($body['results'] as $r) {
            self::assertLessThanOrEqual(1.7, $r['distanceKm']);
        }
    }

    public function testNoMatchIsAnEmptyResultNotAnError(): void
    {
        $res = $this->get('/search?service=yoga&lat=59.33&lng=18.07&from=2026-10-23&to=2026-10-23');

        self::assertSame(200, $res->status);
        self::assertSame([], $res->body['results']);
    }

    public static function invalidSearches(): array
    {
        $base = 'service=massage&lat=59.33&lng=18.07';

        return [
            'missing service' => ['lat=59.33&lng=18.07&from=2026-10-23&to=2026-10-23'],
            'lat out of range' => ['service=massage&lat=91&lng=18.07&from=2026-10-23&to=2026-10-23'],
            'lng not a number' => ['service=massage&lat=59&lng=abc&from=2026-10-23&to=2026-10-23'],
            'bad date' => ["$base&from=2026-02-30&to=2026-03-01"],
            'to before from' => ["$base&from=2026-10-23&to=2026-10-22"],
            'range too long' => ["$base&from=2026-10-01&to=2026-10-31"],
            'limit zero' => ["$base&from=2026-10-23&to=2026-10-23&limit=0"],
        ];
    }

    #[DataProvider('invalidSearches')]
    public function testInvalidSearchIs400(string $query): void
    {
        $res = $this->get("/search?$query");

        self::assertSame(400, $res->status);
        self::assertSame('validation_error', $res->body['error']['code']);
    }

    public function testBookThenSlotDisappearsFromSearchAndCannotBeBookedAgain(): void
    {
        $slot = ['venueId' => 1, 'serviceId' => 101, 'staffId' => 1001, 'start' => '2026-10-23T09:00:00+02:00', 'customerName' => ' Marco '];

        $res = $this->post($slot);
        self::assertSame(201, $res->status);
        self::assertSame('Marco', $res->body['booking']['customerName']);
        self::assertSame('2026-10-23T10:00:00+02:00', $res->body['booking']['end']);
        self::assertSame('Anna', $res->body['booking']['staff']['name']);

        $slots = $this->get(self::SEARCH)->body['results'][0]['slots'];
        $annaStarts = array_column(array_filter($slots, fn ($s) => $s['staffId'] === 1001), 'start');
        self::assertNotContains('2026-10-23T09:00:00+02:00', $annaStarts);
        self::assertSame('2026-10-23T13:00:00+02:00', $annaStarts[array_key_first($annaStarts)]);
        self::assertSame('Erik', $slots[0]['staffName'], 'Erik (from 10:00) is now the earliest');

        $again = $this->post($slot);
        self::assertSame(409, $again->status);
        self::assertSame('slot_taken', $again->body['error']['code']);
    }

    public static function invalidBookings(): array
    {
        $ok = ['venueId' => 1, 'serviceId' => 101, 'staffId' => 1001, 'start' => '2026-10-23T09:00:00+02:00', 'customerName' => 'Marco'];

        return [
            'not json' => ['{nope', 400, 'validation_error'],
            'json list' => ['[1,2]', 400, 'validation_error'],
            'string id' => [['venueId' => '1'] + $ok, 400, 'validation_error'],
            'no offset' => [['start' => '2026-10-23T09:00:00'] + $ok, 400, 'validation_error'],
            'blank name' => [['customerName' => '   '] + $ok, 400, 'validation_error'],
            'control chars in name' => [['customerName' => "Mar\x07co"] + $ok, 400, 'validation_error'],
            'unknown staff' => [['staffId' => 9999] + $ok, 404, 'not_found'],
            'during break' => [['start' => '2026-10-23T12:00:00+02:00'] + $ok, 422, 'slot_not_offered'],
            'past' => [['start' => '2026-10-01T09:00:00+02:00'] + $ok, 422, 'slot_in_past'],
            'existing booking' => [['start' => '2026-10-23T10:00:00+02:00'] + $ok, 409, 'slot_taken'],
        ];
    }

    #[DataProvider('invalidBookings')]
    public function testInvalidBookings(array|string $body, int $status, string $code): void
    {
        $res = $this->post(is_string($body) ? $body : json_encode($body));

        self::assertSame($status, $res->status, json_encode($res->body));
        self::assertSame($code, $res->body['error']['code']);
    }

    public function testUnknownRouteAndWrongMethod(): void
    {
        self::assertSame(404, $this->get('/nope')->status);
        self::assertSame(405, $this->api->handle('POST', '/search')->status);
        self::assertSame(405, $this->get('/bookings')->status);
    }
}
