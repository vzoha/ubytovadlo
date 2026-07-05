<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Zdroj dat, který lze v UI zapnout/vypnout a sledovat jeho zdraví. Nejde o
 * kanál rezervace (viz {@see Channel}) — jeden e-mailový konektor produkuje
 * rezervace i platby. MotoPress jezdí přes REST, Booking/Airbnb/banka přes IMAP.
 */
enum ConnectorType: string
{
    case MOTOPRESS = 'motopress';
    case BOOKING = 'booking';
    case AIRBNB = 'airbnb';
    case BANK_CS = 'bank_cs';

    public function label(): string
    {
        return match ($this) {
            self::MOTOPRESS => 'Web (MotoPress)',
            self::BOOKING => 'Booking.com',
            self::AIRBNB => 'Airbnb',
            self::BANK_CS => 'Banka (Česká spořitelna)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::MOTOPRESS => 'Import rezervací z vlastního webu přes REST API.',
            self::BOOKING => 'Trigger nových rezervací z Booking notifikací (e-mail).',
            self::AIRBNB => 'Rezervace a výplaty z přeposlaných Airbnb e-mailů.',
            self::BANK_CS => 'Párování příchozích plateb z bankovních notifikací (e-mail).',
        };
    }

    /** Jezdí přes automatizační schránku (IMAP), ne přes vlastní přístup. */
    public function usesImap(): bool
    {
        return $this !== self::MOTOPRESS;
    }

    /**
     * Konektory, které sdílejí IMAP transport (automatizační schránku).
     *
     * @return list<self>
     */
    public static function imapConnectors(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $t): bool => $t->usesImap()));
    }
}
