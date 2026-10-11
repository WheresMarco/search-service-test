# AI usage

## Tools
- **Claude Code (Claude Opus 5.5)** was used for:
  - analysing `data.json`
  - asking clarifying questions and writing the plan (`PLAN.md`)
  - scaffolding the code and tests
  - running the end-to-end checks

- **A separate Claude Code (Claude Opus 5.5) Agent** was used to validate the code through a code review independent of the agent that built the code. Based on https://github.com/anthropics/claude-code/blob/main/plugins/code-review/README.md
  
- **I made the design decisions:** no framework, SQLite, substring matching, per-staff slots that booking re-validates. I picked them from options the AI laid out, and the AI's plan followed them.

## Where AI output was corrected or rejected
1. **Booking end time was computed twice.** The first version of `BookingService` recomputed the end with `$start->modify("+N minutes")`. That duplicated the slot logic, and wall-clock arithmetic is exactly what goes wrong on the DST day. Changed so the booking takes `start`/`end` from the slot `SlotFinder` returned, so there's one source of truth.
2. **A test helper didn't test anything.** The first `BookingServiceTest` helper checked that *some* `BookingFailed` was thrown, never *which* reason. Every rejection test would have passed even with the wrong status code. Replaced with an explicit `assertFails($reason, ...)`.
3. **A test was wrong, not the code.** An API test assumed Anna's 13:00 would be the first slot after booking 09:00. It failed because Erik also does Klassisk massage and is free from 10:00 on Fridays. Checking against `data.json` showed the code was right, so the test was fixed rather than the code bent to match it.

## How output was verified
- **Unit tests built around the traps in `data.json`:** DST, mixed offsets, overlapping and cancelled bookings, the null position, staff hours vs venue hours.
- **A manual curl run against `php -S`.**
- **A concurrency test:** 10 parallel POSTs for the same slot gave exactly one 201. It only means something with several server workers, because a plain `php -S` handles one request at a time. `bin/smoke-test` sets `PHP_CLI_SERVER_WORKERS=8`, and a unit test calls the lock-protected overlap check directly.
- **Smoke test:** with `bin/smoke-test`. Starts its own server on a free port and tests the API over real HTTP. The 11 checks cover the data.json traps, the error codes, booking the same slot twice, and 10 simultaneous bookings for one slot (exactly one must succeed).
- **Custom Agent:** `.claude/agents/service-tester.md`. A Claude Code agent that acts as a QA tester. It runs PHPUnit and the smoke test, then starts its own isolated server and tests things the scripts don't cover. For example, it works out free slots by hand from data.json and compares them with search, and tries timezone variants, odd inputs, and parallel bookings of overlapping slots.