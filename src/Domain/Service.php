<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Service
{
    public function __construct(
        public int $id,
        public string $name,
        public int $durationMinutes,
        public int $priceSek,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'durationMinutes' => $this->durationMinutes,
            'priceSek' => $this->priceSek,
        ];
    }
}
