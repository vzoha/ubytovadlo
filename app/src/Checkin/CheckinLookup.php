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
use App\Formatting\PersonName;
use App\Repository\ReservationRepository;

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
    /** Kratší kód by hledání zbytečně rozšířil na náhodné shody. */
    private const MIN_CODE_LENGTH = 3;

    /** Jednopísmenné příjmení by z druhého faktoru udělalo formalitu. */
    private const MIN_LAST_NAME_LENGTH = 2;

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly CheckinWindow $window,
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

        foreach ($this->reservations->findByGuestCode($code) as $reservation) {
            if ($this->matches($reservation, $lastName)) {
                return $reservation;
            }
        }

        return null;
    }

    private function matches(Reservation $reservation, string $lastName): bool
    {
        return $reservation->getCheckinToken() !== null
            && $this->window->allowsLookup($reservation)
            && $this->hasLastName($reservation, $lastName);
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
        return (string) preg_replace('/\s+/', ' ', PersonName::fold($name));
    }
}
