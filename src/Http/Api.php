<?php

declare(strict_types=1);

namespace App\Http;

use App\Booking\BookingFailed;
use App\Booking\BookingService;
use App\Data\BookingStore;
use App\Data\DataRepository;
use App\Domain\Clock;
use App\Search\SearchService;
use Closure;
use DateTimeImmutable;
use Throwable;

/**
 * Routing, input validation and error mapping. No framework: two endpoints don't need one.
 *
 *   GET  /search?service=&lat=&lng=&from=YYYY-MM-DD&to=YYYY-MM-DD[&radiusKm=][&limit=]
 *   POST /bookings  {"venueId", "serviceId", "staffId", "start", "customerName"}
 */
final class Api
{
    public const MAX_RANGE_DAYS = 14;

    private readonly SearchService $search;
    private readonly BookingService $booking;

    /** @param Closure(): DateTimeImmutable $clock */
    public function __construct(
        private readonly DataRepository $data,
        BookingStore $store,
        private readonly Closure $clock,
    ) {
        $this->search = new SearchService($data, $store);
        $this->booking = new BookingService($data, $store);
    }

    public static function boot(string $root): self
    {
        $data = DataRepository::fromFile($root . '/data.json');
        $store = BookingStore::open($root . '/var/bookings.sqlite');
        $store->seedOnce($data->bookings);

        return new self($data, $store, Clock::now(...));
    }

    public function handle(string $method, string $uri, string $body = ''): Response
    {
        $path = rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/') ?: '/';
        parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $query);

        $routes = [
            '/search' => ['GET' => fn () => $this->search($query)],
            '/bookings' => ['POST' => fn () => $this->book($body)],
        ];

        try {
            if (!isset($routes[$path])) {
                throw new ApiError(404, 'not_found', "No route for $path. Use GET /search or POST /bookings.");
            }
            if (!isset($routes[$path][$method])) {
                throw new ApiError(405, 'method_not_allowed', "$method is not allowed on $path.");
            }

            return $routes[$path][$method]();
        } catch (ApiError $e) {
            return Response::error($e);
        } catch (Throwable $e) {
            error_log((string) $e);

            return Response::error(new ApiError(500, 'internal_error', 'Something went wrong.'));
        }
    }

    private function search(array $q): Response
    {
        $service = self::string($q, 'service', 100);
        $lat = self::float($q, 'lat', -90, 90);
        $lng = self::float($q, 'lng', -180, 180);
        $from = self::date($q, 'from');
        $to = self::date($q, 'to');
        $radiusKm = isset($q['radiusKm']) ? self::float($q, 'radiusKm', 0, 20000) : null;
        $limit = isset($q['limit']) ? self::int($q, 'limit', 1, 100) : null;

        if ($to < $from) {
            throw ApiError::validation("'to' must not be before 'from'.");
        }
        if ($from->diff($to)->days >= self::MAX_RANGE_DAYS) {
            throw ApiError::validation('The date range may cover at most ' . self::MAX_RANGE_DAYS . ' days.');
        }

        $result = $this->search->search($service, $lat, $lng, $from, $to, $radiusKm, $limit, ($this->clock)());

        return new Response(200, [
            'query' => [
                'service' => trim($service),
                'position' => ['lat' => $lat, 'lng' => $lng],
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'timezone' => Clock::TIMEZONE,
            ],
        ] + $result);
    }

    private function book(string $body): Response
    {
        try {
            $input = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiError::validation('Request body must be valid JSON.');
        }
        if (!is_array($input) || array_is_list($input)) {
            throw ApiError::validation('Request body must be a JSON object.');
        }

        $venueId = self::id($input, 'venueId');
        $serviceId = self::id($input, 'serviceId');
        $staffId = self::id($input, 'staffId');
        $start = self::instant($input, 'start');
        $customerName = self::customerName($input);

        try {
            $booking = $this->booking->book($venueId, $serviceId, $staffId, $start, $customerName, ($this->clock)());
        } catch (BookingFailed $e) {
            $status = match ($e->reason) {
                BookingFailed::NOT_FOUND => 404,
                BookingFailed::TAKEN => 409,
                default => 422,
            };
            throw new ApiError($status, $e->reason, $e->getMessage());
        }

        $venue = $this->data->venue($venueId);

        return new Response(201, ['booking' => [
            'id' => $booking->id,
            'status' => $booking->status,
            'customerName' => $booking->customerName,
            'start' => Clock::local($booking->start)->format(DATE_ATOM),
            'end' => Clock::local($booking->end)->format(DATE_ATOM),
            'venue' => $venue->toArray(),
            'service' => $venue->service($serviceId)->toArray(),
            'staff' => ['id' => $staffId, 'name' => $venue->staffMember($staffId)->name],
        ]]);
    }

    private static function string(array $in, string $key, int $maxLength): string
    {
        $value = $in[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw ApiError::validation("'$key' is required.");
        }
        if (mb_strlen($value) > $maxLength) {
            throw ApiError::validation("'$key' must be at most $maxLength characters.");
        }

        return $value;
    }

    private static function float(array $in, string $key, float $min, float $max): float
    {
        $value = filter_var($in[$key] ?? null, FILTER_VALIDATE_FLOAT);
        if ($value === false || $value < $min || $value > $max) {
            throw ApiError::validation("'$key' must be a number between $min and $max.");
        }

        return $value;
    }

    private static function int(array $in, string $key, int $min, int $max): int
    {
        $value = filter_var($in[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($value === false) {
            throw ApiError::validation("'$key' must be an integer between $min and $max.");
        }

        return $value;
    }

    private static function date(array $in, string $key): DateTimeImmutable
    {
        $value = $in[$key] ?? null;
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, Clock::tz()) : false;
        // Round-trip check rejects overflowing dates such as 2026-02-30.
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ApiError::validation("'$key' must be a date in YYYY-MM-DD format (Europe/Stockholm local date).");
        }

        return $date;
    }

    private static function id(array $in, string $key): int
    {
        if (!is_int($in[$key] ?? null) || $in[$key] < 1) {
            throw ApiError::validation("'$key' must be a positive integer.");
        }

        return $in[$key];
    }

    /** Requires an explicit offset: a bare local time would be ambiguous (and is twice a year in Stockholm). */
    private static function instant(array $in, string $key): DateTimeImmutable
    {
        $value = $in[$key] ?? null;
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw ApiError::validation("'$key' must be an ISO-8601 date-time with offset, e.g. 2026-10-23T09:00:00+02:00.");
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            throw ApiError::validation("'$key' is not a valid date-time.");
        }
    }

    private static function customerName(array $in): string
    {
        $name = trim(self::string($in, 'customerName', 100));
        if (preg_match('/\p{Cc}/u', $name) !== 0) {
            throw ApiError::validation("'customerName' contains invalid characters.");
        }

        return $name;
    }
}
