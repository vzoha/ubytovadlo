<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Timeline;

use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Enum\ActionType;
use App\Enum\ReservationStatus;
use App\Invoice\DepositConfig;
use App\Repository\ReservationActionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Zakládá automatické akce na časovou osu rezervace. Idempotentní — akci daného
 * typu nezaloží podruhé (a tím respektuje i ruční zrušení existující akce).
 * Pouze persistuje, flush nechává na volajícím.
 */
class ReservationActionPlanner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReservationActionRepository $actions,
        private readonly DepositConfig $depositConfig,
    ) {
    }

    /**
     * @return int počet nově založených akcí
     */
    public function planFor(Reservation $reservation): int
    {
        if (in_array($reservation->getStatus(), [ReservationStatus::CANCELLED, ReservationStatus::COMPLETED], true)) {
            return 0;
        }

        // Pobyt už dávno skončil — nemá smysl plánovat budoucí akce zpětně.
        $end = $reservation->getCheckOut() ?? $reservation->getCheckIn();
        if ($end < new \DateTimeImmutable('today')) {
            return 0;
        }

        $checkIn = $reservation->getCheckIn();
        $checkOut = $reservation->getCheckOut();
        $added = 0;

        $added += $this->ensure($reservation, ActionType::PRE_ARRIVAL_MESSAGE, $this->at($checkIn, '-3 days', '09:00'));

        if ($checkOut !== null) {
            $added += $this->ensure($reservation, ActionType::POST_STAY_MESSAGE, $this->at($checkOut, '+1 day', '10:00'));
        }

        // Doplatek + připomínka jen u toku se zálohou; při „bez zálohy" jde web
        // klasika na jednu fakturu, doplatková akce nedává smysl.
        if ($this->depositConfig->appliesTo($reservation->getBillingMode())) {
            $added += $this->ensure($reservation, ActionType::ISSUE_FINAL_INVOICE, $this->at($checkIn, null, '10:00'));
            $added += $this->ensure($reservation, ActionType::BALANCE_REMINDER, $this->at($checkIn, null, '12:00'));
        }

        // Ubyport — jen u cizinců (host z jiné země než ČR), lhůta 3 dny od příjezdu.
        $country = $reservation->getGuestCountry();
        if ($country !== null && $country !== 'CZ') {
            $added += $this->ensure($reservation, ActionType::UBYPORT_EXPORT, $this->at($checkIn, '+1 day', '09:00'));
        }

        return $added;
    }

    private function ensure(Reservation $reservation, ActionType $type, \DateTimeImmutable $when): int
    {
        if ($this->actions->hasOfType($reservation, $type)) {
            return 0;
        }
        $this->em->persist(new ReservationAction($reservation, $type, $when));

        return 1;
    }

    private function at(\DateTimeImmutable $date, ?string $modify, string $time): \DateTimeImmutable
    {
        $result = $date;
        if ($modify !== null) {
            $result = $result->modify($modify);
        }
        [$h, $m] = array_map('intval', explode(':', $time));

        return $result->setTime($h, $m);
    }
}
