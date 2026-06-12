<?php

declare(strict_types=1);

namespace App\Email\Dto;

final class BookingTriggerData
{
    public function __construct(
        public readonly string $reservationId,
        public readonly \DateTimeImmutable $checkIn,
    ) {
    }
}
