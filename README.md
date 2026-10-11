# Booking service

A small JSON HTTP API for finding a bookable service near a location and booking a time slot.
Plain PHP, no framework. Bookings are stored in SQLite and `data.json` is never modified.

## Run

Requires PHP >= 8.2 with `pdo_sqlite` and `mbstring` (both are in the default PHP build). `intl` is optional.

```sh
composer install
php -S localhost:8000 -t public public/index.php     # or: composer serve
vendor/bin/phpunit                                    # or: composer test
bin/check-data                                        # reports inconsistencies in data.json
```

On the first request, `var/bookings.sqlite` is created and seeded with the bookings from `data.json`.
Delete that file to reset the database, or after `data.json` changes.

## API

All times are `Europe/Stockholm`. Responses give ISO-8601 times with offset; requests may use any offset.

### `GET /search`

| param | required | |
|---|---|---|
| `service` | yes | free text; case- and accent-insensitive substring match on the service name |
| `lat`, `lng` | yes | customer position |
| `from`, `to` | yes | local dates `YYYY-MM-DD`, inclusive, at most 14 days |
| `radiusKm` | no | max distance |
| `limit` | no | max number of results (1–100) |

```sh
curl 'localhost:8000/search?service=massage&lat=59.3293&lng=18.0686&from=2026-10-23&to=2026-10-26'
```

Returns one result per (venue, matching service), sorted by distance:

```json
{
  "query": { "service": "massage", "position": {...}, "from": "2026-10-23", "to": "2026-10-26", "timezone": "Europe/Stockholm" },
  "results": [{
    "venue": { "id": 1, "name": "Salongens Massage", "position": { "lat": 59.3166428, "lng": 18.0561183 } },
    "service": { "id": 101, "name": "Klassisk massage", "durationMinutes": 60, "priceSek": 795 },
    "distanceKm": 1.58,
    "slots": [{ "staffId": 1001, "staffName": "Anna", "start": "2026-10-23T09:00:00+02:00", "end": "2026-10-23T10:00:00+02:00" }]
  }],
  "excluded": [{ "venue": { "id": 4, "name": "Studio Norr", "position": null }, "reason": "missing_position" }]
}
```

### `POST /bookings`

Send a slot from the search result, with the venue, service and customer name:

```sh
curl -X POST localhost:8000/bookings -d '{
  "venueId": 1, "serviceId": 101, "staffId": 1001,
  "start": "2026-10-23T09:00:00+02:00", "customerName": "Marco"
}'
```

`201` returns `{"booking": {id, status, customerName, start, end, venue, service, staff}}`.

Every error has the form `{"error": {"code": "...", "message": "..."}}`:

| status | code | when |
|---|---|---|
| 400 | `validation_error` | missing or malformed input (including a `start` without an offset) |
| 404 | `not_found` | unknown venue, service or staff, or one that doesn't belong to the venue |
| 405 / 404 | `method_not_allowed` / `not_found` | wrong method or route |
| 409 | `slot_taken` | slot already booked (also if lost to a concurrent request) |
| 422 | `slot_not_offered` / `slot_in_past` | time is outside hours, during a break, off the 15-min grid, or in the past |

Note: slots in the past are never returned, so a search over dates that have already passed returns venues with empty `slots`.
