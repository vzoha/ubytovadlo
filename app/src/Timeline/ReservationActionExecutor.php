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
 *  - Zprávy hostům se NEodesílají (čeká na roadmap „Zprávy hostům"). Dokud je
 *    akce v okně platnosti (pre-arrival před příjezdem, post-stay do 3 dnů po
 *    odjezdu), zůstane PLANNED — pošle se, až bude odesílač. Po vypršení okna se
 *    označí SKIPPED, aby se prošlé zprávy nikdy neposlaly zpětně a nevisely na ose.
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
    public function execute(ReservationAction $action, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
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
            ActionType::PRE_ARRIVAL_MESSAGE,
            ActionType::POST_STAY_MESSAGE,
            ActionType::CUSTOM_MESSAGE => $this->skipIfStale($action, $now),
            // Ruční připomínky (CUSTOM_REMINDER) řeší majitelka sama.
            default => false,
        };
    }

    /**
     * Zprávy hostům se v MVP neodesílají. Dokud je akce v okně platnosti, necháme
     * ji PLANNED (pošle se, až bude odesílač). Po vypršení okna ji označíme SKIPPED,
     * aby se prošlá zpráva nikdy neposlala zpětně. Okno:
     *  - pre-arrival: do příjezdu hosta,
     *  - post-stay:   do 3 dnů po odjezdu,
     *  - custom:      do 3 dnů po naplánovaném termínu (backstop).
     */
    private function skipIfStale(ReservationAction $action, \DateTimeImmutable $now): bool
    {
        $reservation = $action->getReservation();
        $deadline = match ($action->getType()) {
            ActionType::PRE_ARRIVAL_MESSAGE => $reservation->getCheckIn(),
            ActionType::POST_STAY_MESSAGE => ($reservation->getCheckOut() ?? $reservation->getCheckIn())->modify('+3 days'),
            default => $action->getScheduledFor()->modify('+3 days'),
        };

        if ($now > $deadline) {
            $action->markSkipped('Po termínu — zpráva neodeslána (mimo okno platnosti).');

            return true;
        }

        return false;
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
