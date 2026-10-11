<?php

declare(strict_types=1);

namespace App\Availability;

use App\Domain\Clock;
use App\Domain\Staff;
use DateTimeImmutable;

final readonly class Slot
{
    public function __construct(
        public Staff $staff,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {
    }

    public function toArray(): array
    {
        return [
            'staffId' => $this->staff->id,
            'staffName' => $this->staff->name,
            'start' => Clock::local($this->start)->format(DATE_ATOM),
            'end' => Clock::local($this->end)->format(DATE_ATOM),
        ];
    }
}
