# Booking service (PHP) — implementation plan

## Context
Job coding test, 2h timebox. Build a JSON HTTP API with **search** (service name + lat/lng + date range → venues, service, distance, free slots) and **book** (slot + customer name → booking or clear error). Must run right after clone: only `php` + `composer install`. Repo currently contains only `data.json` (4 venues, 7 bookings) — treated as read-only production data.

Decisions agreed with user: HTTP JSON API without a framework (`php -S`), SQLite storage seeded from data.json, case/accent-insensitive substring matching, per-staff slots that booking re-validates.

## Data traps to handle (and test)
| Trap | Handling |
|---|---|
| Venue 4 `position: null` | Excluded from search (distance is part of the contract); documented |
| b4/b5 overlap (Sara, 2026-10-25) | Don't crash; both count as busy (union of intervals) |
| 2026-10-25 DST switch, mixed offsets (`+02:00`, `+01:00`, `Z`) | Parse all to `DateTimeImmutable`, compare in UTC; generate slots in `Europe/Stockholm` wall time |
| No timezone in data | Assume `Europe/Stockholm` (all venues are in Stockholm) |
| b2 overlaps Anna's break | Booking still blocks; break still blocks — just the union |
| b3 `cancelled` | Ignored as busy |
| Staff hours outside venue hours (Erik Sat 10–18 vs venue 10–15) | Available = staff hours ∩ venue hours − breaks − bookings |
| Staff `serviceIds` | Only staff who perform the service get slots |

## Structure
```
composer.json            # autoload PSR-4 App\ => src/, require-dev phpunit
public/index.php         # front controller: routing, JSON in/out, error mapping
src/
  Data/DataRepository.php    # loads data.json into plain arrays/value objects
  Data/BookingStore.php      # SQLite (PDO): init schema, seed from data.json on first run, insert w/ transaction
  Search/ServiceMatcher.php  # normalize: mb_strtolower + Transliterator 'Any-Latin; Latin-ASCII'; substring
  Search/Distance.php        # haversine, km
  Availability/SlotFinder.php# core: free slots per staff for service + date range
  Booking/BookingService.php # validate + re-check availability + insert atomically
  Http/ApiException.php      # status code + error code + message
tests/                     # PHPUnit
var/                       # bookings.sqlite (gitignored)
README.md, DECISIONS.md, AI.md
```

## API
**GET /search?service=massage&lat=59.33&lng=18.06&from=2026-10-23&to=2026-10-25**
- Validation: required params, lat/lng ranges, `from <= to`, range max 14 days (cap), dates interpreted as local Stockholm days, inclusive.
- Optional `radiusKm` (default none) and `limit`.
- Response sorted by distance:
```json
{ "results": [ {
  "venue": {"id":1,"name":"Salongens Massage","position":{...}},
  "service": {"id":101,"name":"Klassisk massage","durationMinutes":60,"priceSek":795},
  "distanceKm": 1.23,
  "slots": [ {"staffId":1001,"staffName":"Anna","start":"2026-10-23T09:00:00+02:00","end":"2026-10-23T10:00:00+02:00"} ]
} ] }
```
- Slots in local time with offset (ISO 8601), 15-minute step, no slots in the past.

**POST /bookings** `{"venueId":1,"serviceId":101,"staffId":1001,"start":"2026-10-23T09:00:00+02:00","customerName":"Marco"}`
- 201 + booking `{id, venueId, serviceId, staffId, start, end, customerName, status:"confirmed"}`
- Errors as `{"error":{"code":"...","message":"..."}}`: 400 validation, 404 unknown venue/service/staff, 422 staff doesn't do the service / slot not on grid, 409 slot no longer available.
- Re-validation reuses `SlotFinder` for that staff + day: the requested start must be one of the generated slots. Single source of truth.
- Concurrency: `BEGIN IMMEDIATE` transaction in SQLite → overlap query on staffId → insert. Prevents double booking from parallel requests.

## Core algorithm (SlotFinder)
For each local day in range → for each matching service → for each qualified staff:
1. window = staff hours[day] ∩ venue hours[day] (built as `DateTimeImmutable` in Europe/Stockholm, so DST is correct)
2. busy = breaks for that day + confirmed bookings for staff (from SQLite, which includes seeded data.json bookings)
3. step 15 min from window start; slot valid if `[t, t+duration]` fits inside the window and doesn't overlap any busy interval (half-open intervals).
Pure function over inputs (`now` injected) → easy to unit test.

## Tests (PHPUnit) — the most important parts
- SlotFinder: break excluded; cancelled booking ignored; overlapping b4/b5 handled; staff ∩ venue hours (Erik Sat ends 15:00); `Z` booking b6 blocks 10:00 local; DST day 2026-10-25 slots are correct; staff without the service gets no slots; duration fits before close.
- ServiceMatcher: `massage` matches Sportmassage/Djupvävnadsmassage, case-insensitive, `djupvavnad` matches via accent folding, `Massör` doesn't match massage.
- Distance: known haversine value; null-position venue excluded.
- BookingService: success; double-booking → 409; booking b2's time → 409; off-grid/outside hours → 422; empty customer name → 400.
- A couple of HTTP-level smoke tests calling the front-controller handler directly (no server).

## Additional edge cases to catch, fix and document
**Data / domain**
- Opening hours and working hours mark closed days as either `null` or a missing key. Treat both as closed.
- A booking's busy interval comes from its own `start`/`end`, not the service duration, because legacy data is the truth.
- Intervals are half-open: a slot ending at 12:00 is fine next to a break that starts at 12:00, and a slot may end exactly at closing time.
- One venue can match several services ("massage" matches 101, 102 and 103), so there is one result per (venue, service).
- If a venue + service matches but has no free slots, include it with `slots: []` so the consumer can tell it's fully booked rather than missing. Document this.
- No slots or bookings in the past (`now` injected, default `new DateTimeImmutable('now', Stockholm)`). Today is 2026-10-11; all seed bookings are in the future.
- On load, run a data-integrity check that reports overlapping confirmed bookings (b4/b5) and bookings inside breaks (b2). Log them to stderr or a `bin/check-data` script, and list them in DECISIONS.md as "found data issues". This shows judgment about "real production data".

**API / input**
- Book accepts `start` with any ISO-8601 offset (`Z`, `+01:00`) and compares instants. Reject naive datetimes without an offset (400) to avoid timezone ambiguity.
- Customer name: trim, 1–100 chars, reject control characters.
- New booking IDs: random (`bin2hex(random_bytes(8))`) so they can't collide with the legacy `b1`…`b7` IDs.
- No matches gives 200 with `results: []`, not 404.
- Unknown route → 404, wrong method → 405, invalid JSON body → 400. Every error uses the same `{error:{code,message}}` shape with `Content-Type: application/json`.
- Cap the date range at 14 days so search time stays bounded. Return 400 above the cap.
- Round `distanceKm` to 2 decimals.

**Runs after clone**
- `composer.json`: `"php": ">=8.2"`, `ext-pdo_sqlite`, `ext-mbstring`. `intl` is not always installed, so fall back to an `strtr` åäöéü map when `Transliterator` is missing.
- Create `var/` with a `.gitkeep`, gitignore `var/*.sqlite`, and create plus seed the DB automatically on first request. Seed only once; document that changes to data.json after the first run need `rm var/bookings.sqlite`.
- Tests use an in-memory SQLite (`:memory:`) so they never touch the dev DB.
- README: requirements, `composer install`, `php -S localhost:8000 -t public`, curl examples, and `vendor/bin/phpunit`.

**Documented as cut (DECISIONS.md)**
- Idempotency keys for POST retries
- Cancellation endpoint
- Auth and rate limiting
- "Any staff" aggregation
- Synonyms (massör ↔ massage)
- Pagination
- Buffer time between appointments
- Per-venue timezones
- OpenAPI spec

## Commit plan (history matters)
0. `git init`, then copy this plan into the repo as `PLAN.md` (first commit, along with `data.json`)
1. Skeleton (composer, autoload, phpunit, README, .gitignore, var/.gitkeep)
2. Data loading + distance + matcher with tests
3. SlotFinder with tests (traps)
4. SQLite store + BookingService with tests
5. HTTP layer
6. DECISIONS.md + AI.md

## DECISIONS.md content (≤1 page)
Assumptions (timezone, null position, inclusive local dates, 15-min grid, overlapping legacy bookings kept, data.json read-only); trade-offs (no framework, SQLite, compute-on-request); 100x (precompute/cache availability per staff-day, spatial index/geohash or PostGIS, Postgres with exclusion constraint `tstzrange` for bookings, pagination, idempotency keys); cut (auth, cancellation endpoint, "any staff" aggregation, fuzzy/synonym search incl. Massör↔massage, pagination, OpenAPI docs, rate limiting).

## AI.md
List of tools used. Note the 2–3 real corrections **during** implementation (likely candidates: naïve date math that breaks on DST, ignoring `status: cancelled`, ignoring venue opening hours or `position: null`). Only write what actually happened.

## Verification
- `composer install && vendor/bin/phpunit` — all green.
- `php -S localhost:8000 -t public`, then:
  - `curl 'localhost:8000/search?service=massage&lat=59.3293&lng=18.0686&from=2026-10-23&to=2026-10-26'` → confirm Anna has no 10:00–12:30 or 12–13 slots on 10-23, Studio Norr absent, sorted by distance.
  - POST a slot → 201; POST same again → 409; search again → slot gone.
- Fresh clone test: `rm -rf vendor var/*.sqlite && composer install && php -S ...` works.
