<?php

declare(strict_types=1);

namespace App\Enum;

enum ReservationStatus: string
{
    case NEEDS_DETAILS = 'needs_details';
    case CONFIRMED = 'confirmed';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::NEEDS_DETAILS => 'Doplnit údaje',
            self::CONFIRMED => 'Potvrzeno',
            self::IN_PROGRESS => 'Probíhá',
            self::COMPLETED => 'Dokončeno',
            self::CANCELLED => 'Zrušeno',
        };
    }
}
