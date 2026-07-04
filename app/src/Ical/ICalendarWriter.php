<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Ical;

use App\Entity\Reservation;

/**
 * Skládá iCal (RFC 5545) feed obsazenosti pro export do OTA (Booking, Airbnb).
 * Každá rezervace = celodenní VEVENT blok od příjezdu (DTSTART) do odjezdu
 * (DTEND, u iCalu výlučný = den odjezdu je zase volný na výměnu).
 *
 * Feed je neosobní záměrně — ven jde jen „obsazeno", žádné jméno ani cena.
 * OTA potřebuje jen zablokovat termín, hosta si drží u sebe.
 */
final class ICalendarWriter
{
    private const PRODID = '-//Ubytovadlo//Obsazenost//CS';
    private const BUSY_SUMMARY = 'Obsazeno';

    /**
     * @param Reservation[] $reservations
     * @param string        $host         doménová část UID (stabilní identita instance)
     */
    public function build(array $reservations, string $host): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::PRODID,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        foreach ($reservations as $reservation) {
            foreach ($this->event($reservation, $host) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([$this, 'fold'], $lines)) . "\r\n";
    }

    /**
     * @return string[]
     */
    private function event(Reservation $reservation, string $host): array
    {
        $checkIn = $reservation->getCheckIn();
        // Bez odjezdu blokujeme aspoň jednu noc (den příjezdu), ať termín není volný.
        $checkOut = $reservation->getCheckOut() ?? $checkIn->modify('+1 day');
        $stamp = $reservation->getUpdatedAt()->setTimezone(new \DateTimeZone('UTC'));

        return [
            'BEGIN:VEVENT',
            'UID:reservation-' . $reservation->getId() . '@' . $host,
            'DTSTAMP:' . $stamp->format('Ymd\THis\Z'),
            'DTSTART;VALUE=DATE:' . $checkIn->format('Ymd'),
            'DTEND;VALUE=DATE:' . $checkOut->format('Ymd'),
            'SUMMARY:' . $this->escape(self::BUSY_SUMMARY),
            'TRANSP:OPAQUE',
            'END:VEVENT',
        ];
    }

    /** Escapování textových hodnot dle RFC 5545 (zpětné lomítko, čárka, středník, nový řádek). */
    private function escape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
    }

    /**
     * Zalomení dlouhých řádků na 75 oktetů (RFC 5545 §3.1) — pokračovací řádek
     * začíná mezerou. Počítáme v bajtech kvůli UTF-8, ale nelámeme uprostřed znaku.
     */
    private function fold(string $line): string
    {
        if (\strlen($line) <= 75) {
            return $line;
        }

        $folded = '';
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (\strlen($current) + \strlen($char) > 75) {
                $folded .= ($folded === '' ? '' : "\r\n ") . $current;
                $current = '';
            }
            $current .= $char;
        }

        return $folded . ($folded === '' ? '' : "\r\n ") . $current;
    }
}
