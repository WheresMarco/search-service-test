<?php

declare(strict_types=1);

namespace App\Booking;

use RuntimeException;

final class BookingFailed extends RuntimeException
{
    public const NOT_FOUND = 'not_found';
    public const NOT_OFFERED = 'slot_not_offered';
    public const IN_PAST = 'slot_in_past';
    public const TAKEN = 'slot_taken';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
