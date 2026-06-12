<?php

declare(strict_types=1);

namespace App\Enum;

enum Channel: string
{
    case WEB = 'web';
    case BOOKING = 'booking';
    case AIRBNB = 'airbnb';

    public function label(): string
    {
        return match ($this) {
            self::WEB => 'Web',
            self::BOOKING => 'Booking.com',
            self::AIRBNB => 'Airbnb',
        };
    }
}
