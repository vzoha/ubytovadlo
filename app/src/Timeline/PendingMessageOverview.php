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
use App\Repository\ReservationActionRepository;

/**
 * Zprávy hostům, kterým nadešel čas a čekají na ruční odeslání — napříč všemi
 * rezervacemi. Podklad pro kartu na přehledu, aby se otevřené zprávy daly najít
 * jinde než na ose jedné rezervace.
 */
final class PendingMessageOverview
{
    public function __construct(
        private readonly ReservationActionRepository $actions,
    ) {
    }

    /**
     * @return list<array{
     *   reservation: Reservation,
     *   action: ReservationAction,
     *   overdue: bool,
     *   byChat: bool
     * }>
     */
    public function due(\DateTimeImmutable $today): array
    {
        $types = array_values(array_filter(
            ActionType::cases(),
            static fn (ActionType $type): bool => $type->sendsGuestMessage(),
        ));

        $rows = [];
        foreach ($this->actions->findOpenMessages($today->modify('+1 day'), $types) as $action) {
            $reservation = $action->getReservation();
            $rows[] = [
                'reservation' => $reservation,
                'action' => $action,
                // Bez e-mailu zbývá chat portálu — karta to říká rovnou.
                'byChat' => $reservation->getGuestContact()->getEmail() === null,
                'overdue' => $action->getScheduledFor() < $today,
            ];
        }

        return $rows;
    }
}
