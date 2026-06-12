<?php

declare(strict_types=1);

namespace App\MotoPress;

enum MotoPressBookingKind
{
    case WEB;
    case IMPORTED_AIRBNB;
    case IMPORTED_BOOKING;
    case IMPORTED_UNKNOWN;
}
