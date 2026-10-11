<?php

declare(strict_types=1);

namespace App\Search;

use App\Availability\Slot;
use App\Availability\SlotFinder;
use App\Data\BookingStore;
use App\Data\DataRepository;
use App\Domain\Clock;
use DateTimeImmutable;

final class SearchService
{
    public function __construct(
        private readonly DataRepository $data,
        private readonly BookingStore $store,
        private readonly SlotFinder $slotFinder = new SlotFinder(),
    ) {
    }

    /**
     * One result per (venue, matching service), sorted by distance. A result with no free slots is kept
     * (with "slots": []) so a consumer can tell "fully booked" apart from "not offered here".
     *
     * @return array{results: list<array>, excluded: list<array>}
     */
    public function search(
        string $query,
        float $lat,
        float $lng,
        DateTimeImmutable $fromDay,
        DateTimeImmutable $toDay,
        ?float $radiusKm,
        ?int $limit,
        DateTimeImmutable $now,
    ): array {
        $rangeStart = Clock::at($fromDay, '00:00');
        $bookings = $this->store->confirmedBetween($rangeStart, Clock::at($toDay, '00:00')->modify('+1 day'));

        $results = [];
        $excluded = [];
        foreach ($this->data->venues as $venue) {
            $services = array_filter($venue->services, fn ($s) => ServiceMatcher::matches($query, $s->name));
            if ($services === []) {
                continue;
            }
            if (!$venue->hasPosition()) {
                // Distance is part of the result contract, so a venue we can't place is reported, not guessed.
                $excluded[] = ['venue' => $venue->toArray(), 'reason' => 'missing_position'];
                continue;
            }

            $distance = Distance::km($lat, $lng, $venue->lat, $venue->lng);
            if ($radiusKm !== null && $distance > $radiusKm) {
                continue;
            }

            foreach ($services as $service) {
                $slots = [];
                foreach ($venue->staffFor($service) as $staff) {
                    array_push($slots, ...$this->slotFinder->find($venue, $service, $staff, $fromDay, $toDay, $bookings, $now));
                }
                usort($slots, fn (Slot $a, Slot $b) => [$a->start, $a->staff->name] <=> [$b->start, $b->staff->name]);

                $results[] = [
                    'venue' => $venue->toArray(),
                    'service' => $service->toArray(),
                    'distanceKm' => round($distance, 2),
                    'slots' => array_map(fn (Slot $s) => $s->toArray(), $slots),
                ];
            }
        }

        usort($results, fn (array $a, array $b) => [$a['distanceKm'], $a['venue']['id'], $a['service']['id']]
            <=> [$b['distanceKm'], $b['venue']['id'], $b['service']['id']]);

        return [
            'results' => $limit === null ? $results : array_slice($results, 0, $limit),
            'excluded' => $excluded,
        ];
    }
}
