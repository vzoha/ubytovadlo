<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Reservation;

use App\Cashflow\IncomeUpserter;
use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Repository\ReservationActionRepository;
use App\Timeline\ReservationActionPlanner;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Storno rezervace se vším, co k němu patří: zavře naplánované akce, ať hostovi
 * nedojde zpráva ke zrušenému pobytu, a přepočte příjem — zrušený pobyt vede jen
 * reálně přijaté peníze (nevrácená záloha, storno poplatek), ne odhad výplaty.
 *
 * Obnovení je opak: vrátí akce, které storno zavřelo, a stav podle kalendáře.
 */
final class ReservationCanceller
{
    /** Výsledek akce zavřené stornem — podle něj se pozná, co obnovení vrátí zpět. */
    public const ACTION_CANCELLED_BY_RESERVATION = 'Rezervace zrušena.';

    public function __construct(
        private readonly ReservationActionRepository $actions,
        private readonly IncomeUpserter $incomeUpserter,
        private readonly ReservationActionPlanner $planner,
        private readonly ReservationLifecycle $lifecycle,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function cancel(Reservation $reservation): void
    {
        $reservation->setStatus(ReservationStatus::CANCELLED);

        foreach ($this->actions->findOpenForReservation($reservation) as $action) {
            $action->cancel(self::ACTION_CANCELLED_BY_RESERVATION);
        }

        $this->incomeUpserter->recompute($reservation);
        $this->em->flush();
    }

    /**
     * @return ReservationStatus stav, do kterého se rezervace vrátila
     */
    public function restore(Reservation $reservation): ReservationStatus
    {
        $status = $this->lifecycle->afterRestore($reservation);
        $reservation->setStatus($status);

        foreach ($this->actions->findCancelledWithResult($reservation, self::ACTION_CANCELLED_BY_RESERVATION) as $action) {
            $action->replan();
        }

        $this->incomeUpserter->recompute($reservation);
        // Doplní akce, které mezitím přibyly (nová šablona zpráv, změněný termín).
        $this->planner->planFor($reservation);
        $this->em->flush();

        return $status;
    }
}
