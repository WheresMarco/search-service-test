# Decisions

## Assumptions
- **Timezone is Europe/Stockholm.** The data has no time zone specified, and every venue is in Stockholm. Hours are local times. Bookings use mixed offsets (`+02:00`, `+01:00`, `Z`), so everything is compared as instants. 2026-10-25 is the DST switch, so slot arithmetic uses Unix timestamps and not wall-clock time (tested with PHPUnit tests).
- **Available = staff working hours ∩ venue opening hours − breaks − confirmed bookings.** For example, Erik works Saturdays until 18 but the venue closes at 15, so he is bookable until 15. `null` and a missing weekday both mean closed.
- **Slots start every 15 minutes.** Each slot is tied to a staff member, because the customer may care who does the service and booking needs a concrete person.
- **`data.json` is read-only production data.** It is not "cleaned". Its bookings are copied into SQLite once, and new bookings go only to SQLite. `bin/check-data` reports its issues:
  - **b4/b5 double-book Sara.** Both are kept, and both block time.
  - **b2 runs into Anna's break.** It is kept, and both block time.
  - **Studio Norr has no position.** It is left out of search and listed under `excluded`, because a missing distance is better reported than guessed.
- **Search matches substrings, ignoring case and accents.** "massage" finds Klassisk massage, Massage and Djupvävnadsmassage, but not "Massör - 50 min". (Sportmassage also matches, but Studio Norr only shows up under `excluded`.) That is predictable and explainable; synonyms are cut.
- **The client's slot is never trusted.** Booking re-runs the same `SlotFinder` that search uses: 422 if the time was never offered, 409 if it's taken. The final overlap check runs inside `BEGIN IMMEDIATE`, so two requests can't both pass it. A unit test calls that check directly with an overlapping booking, the way a request that lost the race would. `bin/smoke-test` also sends 10 parallel POSTs for one slot to a server with 8 workers and expects one 201 and nine 409s. Most of those losers are already rejected by the earlier availability check, so the smoke test is end-to-end evidence, not proof that the lock works.
- **Booking `start` must include an offset.** A local time without one is ambiguous, and on fall-back day it happens twice.
- **A matching service with no free time is still returned,** with `slots: []`, so "fully booked" can be told apart from "not offered".

## Trade-offs
- **No framework.** Two endpoints don't justify one. A tiny router and hand-written validation are fast to read, but would not scale to many endpoints. For a more production ready code a framework like Laravel (or any other project based on Symfony) should be used.
- **Availability is computed on every request** from all venues in memory, with the search range capped at 14 days. That is O(venues × staff × days × slots), which is fine here.
- **SQLite instead of a JSON file** buys transactions and a write lock almost for free.

## At 100x data or traffic
- **Postgres:** the time-overlap guarantee becomes a database constraint (an exclusion constraint on `tstzrange` per staff member), and the database handles concurrent writes (SQLite allows only one writer at a time).
- **Geo prefilter:** PostGIS or a geohash index to find nearby venues without computing the distance to every venue.
- **Indexed service names:** full-text or trigram search instead of scanning every name.
- **Cached availability per staff member and day,** cleared when a booking is made or the schedule changes. Search reads the cache instead of recomputing.
- **Duplicate-safe booking:** an idempotency key on POST so client retries can't create two bookings. Then rate limiting and auth.
- **Load data into the DB once at startup** instead of parsing `data.json` on every request.

## What I cut
- **Booking features:** cancelling or changing a booking, booking with "any staff member", buffer time between appointments.
- **Search:** synonyms, typo tolerance and pagination.
- **Hardening:** login, rate limiting, retry-safe POST.
- **Infrastructure:** an OpenAPI spec, structured logging, separate timezones per venue.

These are all valuable, but none is needed to show correct search and booking within the task's limitations (2 hours).
