<?php

declare(strict_types=1);

namespace App\MotoPress;

final class ClassifiedBooking
{
    public function __construct(
        public readonly MotoPressBookingKind $kind,
        public readonly ?string $airbnbConfirmationCode = null,
    ) {
    }
}
