<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Fakturační režim rezervace. Určuje typ faktury (záloha vs jedna na celou),
 * potřebnost firemních údajů a kdo kdy platí.
 *
 * Web rezervace mapujeme z MotoPress payment.gateway_id:
 *   bank   → STANDARD_WITH_DEPOSIT (klasika, záloha 1000 + doplatek)
 *   cash   → FKSP                 (FKSP/firma, jedna na celou po doplnění firmy)
 *   manual → ADMIN_BOOKING        (admin/známí, jedna na celou bez zálohy)
 *
 * OTA rezervace (imported=true v MotoPressu) podle kanálu:
 *   Channel::BOOKING → BOOKING_COM (EUR → CZK kurzem ČNB)
 *   Channel::AIRBNB  → AIRBNB      (už v CZK)
 */
enum BillingMode: string
{
    case STANDARD_WITH_DEPOSIT = 'standard_with_deposit';
    case FKSP = 'fksp';
    case ADMIN_BOOKING = 'admin_booking';
    case AIRBNB = 'airbnb';
    case BOOKING_COM = 'booking_com';
    /** Bezfakturační pobyt — známí, dárek, výměna apod. Pobyt proběhne, faktura se nevystavuje. */
    case WAIVED = 'waived';

    public function label(): string
    {
        return match ($this) {
            self::STANDARD_WITH_DEPOSIT => 'Web klasika (záloha + doplatek)',
            self::FKSP => 'FKSP / firemní',
            self::ADMIN_BOOKING => 'Admin / známí',
            self::AIRBNB => 'Airbnb',
            self::BOOKING_COM => 'Booking.com',
            self::WAIVED => 'Bez fakturace',
        };
    }

    public function requiresDeposit(): bool
    {
        return $this === self::STANDARD_WITH_DEPOSIT;
    }

    public function requiresCompanyDetails(): bool
    {
        return $this === self::FKSP;
    }

    public function isInvoiced(): bool
    {
        return $this !== self::WAIVED;
    }
}
