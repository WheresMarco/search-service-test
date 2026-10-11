<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Venue
{
    /**
     * @param array<string, array{0: string, 1: string}|null> $openingHours keyed by weekday ("mon".."sun")
     * @param Service[] $services
     * @param Staff[] $staff
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?float $lat,
        public ?float $lng,
        public array $openingHours,
        public array $services,
        public array $staff,
    ) {
    }

    public function hasPosition(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /** @return array{0: string, 1: string}|null */
    public function hoursOn(string $weekday): ?array
    {
        return $this->openingHours[$weekday] ?? null;
    }

    public function service(int $id): ?Service
    {
        foreach ($this->services as $service) {
            if ($service->id === $id) {
                return $service;
            }
        }

        return null;
    }

    public function staffMember(int $id): ?Staff
    {
        foreach ($this->staff as $staff) {
            if ($staff->id === $id) {
                return $staff;
            }
        }

        return null;
    }

    /** @return Staff[] */
    public function staffFor(Service $service): array
    {
        return array_values(array_filter($this->staff, fn (Staff $s) => $s->performs($service->id)));
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => $this->hasPosition() ? ['lat' => $this->lat, 'lng' => $this->lng] : null,
        ];
    }
}
