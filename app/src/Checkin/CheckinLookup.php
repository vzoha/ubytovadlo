<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Checkin;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use Psr\Clock\ClockInterface;

/**
 * Vstup do online check-inu bez tokenu: host zadá kód rezervace a své příjmení.
 *
 * Kód sám o sobě je slabé tajemství — u portálů je krátký a čitelný z e-mailu,
 * u webu je to pořadové číslo — proto musí sedět obojí a rezervace musí být
 * v okně kolem pobytu. Výsledkem je jen přesměrování na tokenovou URL, která
 * zůstává jediným nosičem oprávnění.
 */
final class CheckinLookup
{
    /** Jak dlouho před příjezdem má smysl check-in otevírat. */
    private const OPENS_BEFORE_CHECK_IN = 180;

    /** Jak dlouho po odjezdu ještě jde dohledat (Ubyport hlásíme do 3 dnů). */
    private const CLOSES_AFTER_CHECK_OUT = 7;

    /** Kratší kód by hledání zbytečně rozšířil na náhodné shody. */
    private const MIN_CODE_LENGTH = 3;

    /** Jednopísmenné příjmení by z druhého faktoru udělalo formalitu. */
    private const MIN_LAST_NAME_LENGTH = 2;

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Rezervace odpovídající kódu i příjmení, jinak null — bez rozlišení důvodu. */
    public function find(string $code, string $lastName): ?Reservation
    {
        $code = self::normalizeCode($code);
        $lastName = self::normalizeName($lastName);

        if (\strlen($code) < self::MIN_CODE_LENGTH || \strlen($lastName) < self::MIN_LAST_NAME_LENGTH) {
            return null;
        }

        $today = $this->clock->now()->setTime(0, 0);
        foreach ($this->reservations->findByGuestCode($code) as $reservation) {
            if ($this->matches($reservation, $lastName, $today)) {
                return $reservation;
            }
        }

        return null;
    }

    private function matches(Reservation $reservation, string $lastName, \DateTimeImmutable $today): bool
    {
        return $reservation->getCheckinToken() !== null
            && $reservation->getStatus() !== ReservationStatus::CANCELLED
            && $this->isWithinStayWindow($reservation, $today)
            && $this->hasLastName($reservation, $lastName);
    }

    private function isWithinStayWindow(Reservation $reservation, \DateTimeImmutable $today): bool
    {
        $opens = $reservation->getCheckIn()->modify('-' . self::OPENS_BEFORE_CHECK_IN . ' days');
        $closes = ($reservation->getCheckOut() ?? $reservation->getCheckIn())
            ->modify('+' . self::CLOSES_AFTER_CHECK_OUT . ' days');

        return $today >= $opens && $today <= $closes;
    }

    /** Příjmení = poslední slovo (nebo slova) jména hosta, bez ohledu na diakritiku. */
    private function hasLastName(Reservation $reservation, string $lastName): bool
    {
        $name = self::normalizeName($reservation->getGuestName() ?? '');

        return $name === $lastName || str_ends_with($name, ' ' . $lastName);
    }

    /** Kód opisovaný z e-mailu — mezery, pomlčky ani velikost písmen nerozhodují. */
    public static function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s\-\x{00A0}]+/u', '', trim($code)));
    }

    /** Jméno bez diakritiky a vícenásobných mezer, malými písmeny. */
    private static function normalizeName(string $name): string
    {
        $lower = mb_strtolower(trim($name));
        $ascii = self::transliterator()?->transliterate($lower);

        return (string) preg_replace('/\s+/', ' ', \is_string($ascii) ? $ascii : $lower);
    }

    private static function transliterator(): ?\Transliterator
    {
        static $transliterator = null;
        $transliterator ??= \Transliterator::create('Any-Latin; Latin-ASCII');

        return $transliterator;
    }
}
