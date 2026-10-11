# Booking service

A small JSON HTTP API for finding a bookable service near a location and booking a time slot.
Plain PHP, no framework. Bookings are stored in SQLite and `data.json` is never modified.

## Run

Requires PHP >= 8.2 with `pdo_sqlite` and `mbstring` (both are in the default PHP build). `intl` is optional.

```sh
composer install
php -S localhost:8000 -t public public/index.php     # or: composer serve (runs in the foreground)
```

In another terminal:

```sh
vendor/bin/phpunit                                    # or: composer test
bin/check-data                                        # reports inconsistencies in data.json
```

`bin/check-data` exits 1 whenever it finds issues. The shipped `data.json` has known, accepted issues (see `DECISIONS.md`), so it exits 1 on it.

On the first request, `var/bookings.sqlite` is created and seeded with the bookings from `data.json`.
Delete that file to reset the database, or after `data.json` changes.

## API

All times are `Europe/Stockholm`. Responses give ISO-8601 times with offset.
Request times must include an offset as `Z` or `±HH:MM`, and seconds are optional: `2026-10-23T09:00:00+02:00` and `2026-10-23T07:00Z` are both accepted.
Fractional seconds (`.000Z`, as JavaScript's `toISOString()` produces) and compact offsets (`+0200`, `+02`) are rejected with 400.

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
| 400 | `validation_error` | missing or malformed input, a body that isn't a JSON object, or a `start` without an offset |
| 404 | `not_found` | unknown venue, service or staff, or a service or staff member that isn't at that venue |
| 405 / 404 | `method_not_allowed` / `not_found` | wrong method or route |
| 409 | `slot_taken` | slot already booked (also if lost to a concurrent request) |
| 422 | `slot_not_offered` | the staff member doesn't perform the service, or the time is outside hours, during a break or off the 15-min grid |
| 422 | `slot_in_past` | the start time is in the past |
| 500 | `internal_error` | unexpected server error (details go to the server log) |

Note: slots in the past are never returned, so a search over dates that have already passed returns venues with empty `slots`.
To run the server as if it were an earlier date, see `BOOKING_NOW` below.

## Testing

There are three layers, from fastest to most thorough.

### 1. Unit and API tests (PHPUnit)

```sh
vendor/bin/phpunit
```

These cover availability, booking, search matching and the HTTP layer in-process. They use an in-memory database and a fixed clock.

### 2. Black-box smoke test

```sh
bin/smoke-test                              # starts its own server, runs 11 checks, stops it
bin/smoke-test --url=http://localhost:8000  # or test a server that is already running
```

This talks to a real `php -S` server over HTTP. It covers:
- the `data.json` edge cases (breaks, a cancelled booking, overlapping bookings, the DST day)
- validation and error codes
- booking twice
- 10 parallel bookings of the same slot

In the default mode it uses a temporary database and `BOOKING_NOW=2026-10-11T12:00:00+02:00`, so the results don't depend on today's date and never touch `var/bookings.sqlite`.
With `--url`, the server must have a freshly seeded database and a clock before 2026-10-23.
It exits non-zero if any check fails, so it can run in CI.

The server reads two optional environment variables, which the smoke test uses:

| variable | default | |
|---|---|---|
| `BOOKING_DB` | `var/bookings.sqlite` | SQLite file to use |
| `BOOKING_NOW` | real time | fixed "current time", ISO-8601 with offset |

```sh
BOOKING_DB=/tmp/demo.sqlite BOOKING_NOW=2026-10-11T12:00:00+02:00 php -S localhost:8000 -t public public/index.php
```

### 3. Exploratory QA agent (Claude Code)

`.claude/agents/service-tester.md` defines a Claude Code subagent that acts as a QA engineer. It:
- runs the two layers above
- starts its own isolated server
- probes the API for bugs beyond the scripted cases: hand-calculated availability, time zones, input fuzzing, consistency after booking, and concurrent bookings of overlapping slots
- reports findings with `curl` commands to reproduce them

It never changes repository files.

Run it from Claude Code in the repo root:

```text
> Use the service-tester agent to test the service
```

Or run it non-interactively:

```sh
claude -p "Use the service-tester agent to test the service and report findings"
```

`/agents` in Claude Code lists the agent. It needs PHP, Composer and `curl` on the PATH.
