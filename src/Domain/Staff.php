<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Staff
{
    /**
     * @param int[] $serviceIds
     * @param array<string, array{0: string, 1: string}|null> $workingHours keyed by weekday ("mon".."sun")
     * @param list<array{days: string[], start: string, end: string}> $breaks
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $serviceIds,
        public array $workingHours,
        public array $breaks,
    ) {
    }

    public function performs(int $serviceId): bool
    {
        return in_array($serviceId, $this->serviceIds, true);
    }

    /** @return array{0: string, 1: string}|null */
    public function hoursOn(string $weekday): ?array
    {
        return $this->workingHours[$weekday] ?? null;
    }

    /** @return list<array{0: string, 1: string}> */
    public function breaksOn(string $weekday): array
    {
        $result = [];
        foreach ($this->breaks as $break) {
            if (in_array($weekday, $break['days'], true)) {
                $result[] = [$break['start'], $break['end']];
            }
        }

        return $result;
    }
}
