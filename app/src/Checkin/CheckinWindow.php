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
use Psr\Clock\ClockInterface;

/**
 * Časové okno online check-inu. Stránka check-inu ukazuje osobní údaje hostů
 * (data narození, čísla dokladů), takže token rezervace nesmí platit napořád:
 * po odjezdu a krátké lhůtě na hlášení (Ubyport do 3 dnů) se check-in zavře,
 * u zrušené rezervace se neotevře vůbec.
 */
final class CheckinWindow
{
    /** Jak dlouho před příjezdem má smysl check-in dohledávat kódem rezervace. */
    private const OPENS_BEFORE_CHECK_IN = 180;

    /** Jak dlouho po odjezdu je check-in ještě dostupný. */
    private const CLOSES_AFTER_CHECK_OUT = 7;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    /** Token rezervace otevře check-in — nezrušená rezervace, okno se ještě nezavřelo. */
    public function allowsToken(Reservation $reservation): bool
    {
        return $reservation->getStatus() !== ReservationStatus::CANCELLED
            && $this->today() <= $this->closesOn($reservation);
    }

    /** Dohledání kódem rezervace — navíc jen v rozumné době před příjezdem. */
    public function allowsLookup(Reservation $reservation): bool
    {
        $opensAt = $reservation->getCheckIn()->modify('-' . self::OPENS_BEFORE_CHECK_IN . ' days');

        return $this->allowsToken($reservation) && $this->today() >= $opensAt;
    }

    /** Poslední den, kdy token rezervace check-in otevře. */
    public function closesOn(Reservation $reservation): \DateTimeImmutable
    {
        return ($reservation->getCheckOut() ?? $reservation->getCheckIn())
            ->modify('+' . self::CLOSES_AFTER_CHECK_OUT . ' days');
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }
}
