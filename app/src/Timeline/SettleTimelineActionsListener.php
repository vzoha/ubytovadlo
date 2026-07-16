<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Timeline;

use App\Repository\ReservationActionRepository;
use App\Reservation\Event\ReservationFinancialsChangedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Uklízí naplánované akce rezervace, jakmile se jejich cíl splní dřív, než jim
 * nadejde čas: vystavení doplatkové faktury i připomínku doplatku uzavře hned,
 * když faktura existuje / je uhrazeno — bez čekání na cron a bez odeslání
 * zprávy hostovi. Cron app:actions:run zůstává záchranou pro zbylé akce.
 */
#[AsEventListener]
final class SettleTimelineActionsListener
{
    public function __construct(
        private readonly ReservationActionRepository $actions,
        private readonly ReservationActionExecutor $executor,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ReservationFinancialsChangedEvent $event): void
    {
        $changed = false;
        foreach ($this->actions->findOpenForReservation($event->reservation) as $action) {
            if ($this->executor->closeIfSatisfied($action)) {
                $changed = true;
            }
        }

        if ($changed) {
            $this->em->flush();
        }
    }
}
