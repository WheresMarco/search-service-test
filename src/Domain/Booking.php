<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

final readonly class Booking
{
    public const CONFIRMED = 'confirmed';

    public function __construct(
        public string $id,
        public int $staffId,
        public int $serviceId,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public string $status,
        public ?string $customerName = null,
    ) {
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::CONFIRMED;
    }
}
