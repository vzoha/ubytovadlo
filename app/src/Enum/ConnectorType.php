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
    case ECHALUPY = 'echalupy';
    case CS_CHALUPY = 'cs_chalupy';
    case BANK_CS = 'bank_cs';

    public function label(): string
    {
        return match ($this) {
            self::MOTOPRESS => 'Web (MotoPress)',
            self::BOOKING => 'Booking.com',
            self::AIRBNB => 'Airbnb',
            self::ECHALUPY => 'eChalupy',
            self::CS_CHALUPY => 'CS chalupy',
            self::BANK_CS => 'Banka (Česká spořitelna)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::MOTOPRESS => 'Import rezervací z vlastního webu přes REST API.',
            self::BOOKING => 'Trigger z Booking notifikací (e-mail) + obsazenost z iCal feedu.',
            self::AIRBNB => 'Rezervace a výplaty z Airbnb e-mailů + obsazenost z iCal feedu.',
            self::ECHALUPY => 'Obsazenost z iCal feedu eChalupy.',
            self::CS_CHALUPY => 'Obsazenost z iCal feedu CS chalupy.',
            self::BANK_CS => 'Párování příchozích plateb z bankovních notifikací (e-mail).',
        };
    }

    /**
     * Kanál rezervace, do kterého iCal feed tohoto konektoru zakládá obsazenost.
     * Null u konektorů bez iCal importu (MotoPress = REST, banka = platby).
     */
    public function icalChannel(): ?Channel
    {
        return match ($this) {
            self::BOOKING => Channel::BOOKING,
            self::AIRBNB => Channel::AIRBNB,
            self::ECHALUPY => Channel::ECHALUPY,
            self::CS_CHALUPY => Channel::CS_CHALUPY,
            default => null,
        };
    }

    /** Umí konektor importovat obsazenost z iCal feedu? */
    public function supportsIcalImport(): bool
    {
        return $this->icalChannel() !== null;
    }

    /**
     * Konektory, které umí iCal import obsazenosti (mají feed URL a kanál).
     *
     * @return list<self>
     */
    public static function icalConnectors(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $t): bool => $t->supportsIcalImport()));
    }

    /** Jezdí přes automatizační schránku (IMAP) — trigger e-maily / platby. */
    public function usesImap(): bool
    {
        return in_array($this, [self::BOOKING, self::AIRBNB, self::BANK_CS], true);
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
