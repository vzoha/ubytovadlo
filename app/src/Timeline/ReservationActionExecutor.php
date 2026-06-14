<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Timeline;

use App\Entity\ReservationAction;
use App\Enum\ActionType;
use App\Enum\InvoiceType;
use App\Invoice\BalanceCalculator;
use App\Repository\InvoiceRepository;

/**
 * Vyhodnotí naplánovanou akci, které nadešel čas. V MVP:
 *  - Zprávy hostům se NEodesílají (čeká na roadmap „Zprávy hostům") → akce zůstane
 *    PLANNED a dál se zobrazuje jako nadcházející.
 *  - Připomínky se „self-resolvují": pokud majitelka úkol mezitím splnila jinde
 *    (vystavila doplatek, doplatek doražen, host nahlášen na Ubyport), akce se
 *    označí jako hotová. Jinak zůstane PLANNED a na ose nahá jako po termínu.
 *  - CUSTOM_REMINDER řeší majitelka ručně (tlačítkem Hotovo / Zrušit).
 */
class ReservationActionExecutor
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly BalanceCalculator $balance,
    ) {
    }

    /**
     * @return bool true, pokud akce změnila stav (a je třeba flush)
     */
    public function execute(ReservationAction $action): bool
    {
        $reservation = $action->getReservation();

        return match ($action->getType()) {
            ActionType::ISSUE_FINAL_INVOICE => $this->resolveIf(
                $action,
                $this->invoices->findFirstByReservationAndType($reservation, InvoiceType::FINAL) !== null,
                'Doplatková faktura vystavena.',
            ),
            ActionType::BALANCE_REMINDER => $this->resolveIf(
                $action,
                $this->balance->forReservation($reservation)?->isSettled() ?? false,
                'Doplatek uhrazen.',
            ),
            ActionType::UBYPORT_EXPORT => $this->resolveIf(
                $action,
                $reservation->getUbyportExportedAt() !== null,
                'Host nahlášen na Ubyport.',
            ),
            // Zprávy hostům a ruční připomínky se zatím samy nevykonávají.
            default => false,
        };
    }

    private function resolveIf(ReservationAction $action, bool $done, string $message): bool
    {
        if (!$done) {
            return false;
        }
        $action->markDone($message);

        return true;
    }
}
