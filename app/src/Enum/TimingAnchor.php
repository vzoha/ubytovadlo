<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Enum;

use App\Entity\Reservation;

/**
 * Bod na časové ose rezervace, vůči kterému se počítá čas odeslání zprávy. Vlastní
 * posun (o kolik dní dřív/později) drží šablona zprávy odděleně.
 */
enum TimingAnchor: string
{
    case CHECK_IN = 'check_in';
    case CHECK_OUT = 'check_out';
    case CREATED = 'created';

    /** Popisek pro výběr (1. pád). */
    public function label(): string
    {
        return match ($this) {
            self::CHECK_IN => 'příjezd',
            self::CHECK_OUT => 'odjezd',
            self::CREATED => 'objednávka',
        };
    }

    /** Tvar po předložce „před" (7. pád) — „3 dny před příjezdem". */
    public function before(): string
    {
        return match ($this) {
            self::CHECK_IN => 'příjezdem',
            self::CHECK_OUT => 'odjezdem',
            self::CREATED => 'objednávkou',
        };
    }

    /** Tvar po předložce „po" (6. pád) — „1 den po odjezdu". */
    public function after(): string
    {
        return match ($this) {
            self::CHECK_IN => 'příjezdu',
            self::CHECK_OUT => 'odjezdu',
            self::CREATED => 'objednávce',
        };
    }

    /** Přivlastňovací tvar (2. pád) — „v přesný čas příjezdu", „v den odjezdu". */
    public function event(): string
    {
        return match ($this) {
            self::CHECK_IN => 'příjezdu',
            self::CHECK_OUT => 'odjezdu',
            self::CREATED => 'objednávky',
        };
    }

    /** Přesný čas dané události na rezervaci (čas příjezdu/odjezdu), pokud je znám. */
    public function timeFor(Reservation $reservation): ?\DateTimeImmutable
    {
        return match ($this) {
            self::CHECK_IN => $reservation->getCheckInTime(),
            self::CHECK_OUT => $reservation->getCheckOutTime(),
            self::CREATED => null,
        };
    }

    /** Datum kotvy pro danou rezervaci (null, pokud rezervace tento bod nemá). */
    public function dateFor(Reservation $reservation): ?\DateTimeImmutable
    {
        return match ($this) {
            self::CHECK_IN => $reservation->getCheckIn(),
            self::CHECK_OUT => $reservation->getCheckOut(),
            self::CREATED => $reservation->getCreatedAt(),
        };
    }
}
