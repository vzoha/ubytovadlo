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
use App\Enum\InvoiceType;
use App\Enum\OwnerNotificationType;
use App\Enum\PaymentStatus;
use App\Invoice\BalanceCalculator;
use App\Invoice\PaymentStatusResolver;
use App\Notification\OwnerNotifier;
use App\Repository\InvoiceRepository;

/**
 * Vyhodnotí naplánovanou akci, které nadešel čas:
 *  - Zprávy hostům (pre-arrival / post-stay / custom) předá GuestMessageDispatcher,
 *    dokud jsou v okně platnosti. Mimo okno se označí SKIPPED, ať se prošlá zpráva
 *    nepošle zpětně.
 *  - Připomínka doplatku se self-resolvuje, když je doplatek uhrazen; jinak jde
 *    hostovi jedna připomínka.
 *  - Ostatní připomínky (doplatková faktura, Ubyport) se self-resolvují podle
 *    stavu rezervace, CUSTOM_REMINDER řeší majitelka ručně.
 */
class ReservationActionExecutor
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly BalanceCalculator $balance,
        private readonly GuestMessageDispatcher $guestMessages,
        private readonly OwnerNotifier $notifier,
        private readonly PaymentStatusResolver $paymentStatus,
    ) {
    }

    /**
     * @return bool true, pokud akce změnila stav (a je třeba flush)
     */
    public function execute(ReservationAction $action, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return match ($action->getType()) {
            ActionType::ISSUE_FINAL_INVOICE => $this->resolveFinalInvoice($action),
            ActionType::BALANCE_REMINDER => $this->handleBalanceReminder($action),
            ActionType::UBYPORT_EXPORT => $this->handleUbyport($action),
            ActionType::RESERVATION_REQUEST_MESSAGE => $this->handleDepositRequest($action, $now),
            ActionType::PRE_ARRIVAL_MESSAGE,
            ActionType::POST_STAY_MESSAGE,
            ActionType::CUSTOM_MESSAGE => $this->handleGuestMessage($action, $now),
            // Ruční připomínky (CUSTOM_REMINDER) řeší majitelka sama.
            default => false,
        };
    }

    /**
     * Uzavře akci, jejíž cíl je už splněný, dřív než jí nadejde čas — pro
     * událostmi řízené uklízení (vystavena faktura / dorazila platba). Na rozdíl
     * od execute() nikdy nic neodešle: připomínku doplatku jen zavře, když je
     * uhrazeno; neposílá ji, když uhrazeno není. Nesplněné či cizí typy nechá být.
     *
     * @return bool true, pokud akce změnila stav (a je třeba flush)
     */
    public function closeIfSatisfied(ReservationAction $action): bool
    {
        $reservation = $action->getReservation();

        return match ($action->getType()) {
            ActionType::ISSUE_FINAL_INVOICE => $this->resolveFinalInvoice($action),
            ActionType::BALANCE_REMINDER => $this->resolveIf(
                $action,
                $this->balanceSettled($reservation),
                'Doplatek uhrazen.',
            ),
            default => false,
        };
    }

    private function finalInvoiceIssued(Reservation $reservation): bool
    {
        return $this->invoices->findFirstByReservationAndType($reservation, InvoiceType::FINAL) !== null;
    }

    private function balanceSettled(Reservation $reservation): bool
    {
        return $this->balance->forReservation($reservation)?->isSettled() ?? false;
    }

    /**
     * Žádost o zálohu: záloha už dorazila → hotovo (host se nenaguje). Jinak jako
     * ostatní zprávy — okno platnosti do příjezdu, ctí vypnutou šablonu i chybějící
     * e-mail hosta.
     */
    private function handleDepositRequest(ReservationAction $action, \DateTimeImmutable $now): bool
    {
        if ($this->depositPaid($action->getReservation())) {
            $action->markDone('Záloha uhrazena — žádost neodeslána.');

            return true;
        }

        return $this->handleGuestMessage($action, $now);
    }

    /** Přišla na rezervaci alespoň část platby (typicky záloha)? */
    private function depositPaid(Reservation $reservation): bool
    {
        $status = $this->paymentStatus->batch([$reservation])[(int) $reservation->getId()] ?? null;

        return $status !== null && $status !== PaymentStatus::UNPAID;
    }

    /**
     * Zpráva hostovi: mimo okno platnosti → SKIPPED, jinak pokus o odeslání.
     */
    private function handleGuestMessage(ReservationAction $action, \DateTimeImmutable $now): bool
    {
        if ($this->isStale($action, $now)) {
            $action->markSkipped('Po termínu — zpráva neodeslána (mimo okno platnosti).');

            return true;
        }

        return $this->guestMessages->dispatchDue($action);
    }

    /**
     * Ruční odeslání zprávy z časové osy (tlačítko u návrhu) — přeskočí režim
     * i okno platnosti, odešle rovnou.
     *
     * @return bool true, pokud akce změnila stav (a je třeba flush)
     */
    public function sendNow(ReservationAction $action): bool
    {
        return $this->guestMessages->sendNow($action);
    }

    /**
     * Připomínka doplatku: uhrazeno → hotovo; jinak jedna připomínka hostovi.
     */
    private function handleBalanceReminder(ReservationAction $action): bool
    {
        if ($this->balanceSettled($action->getReservation())) {
            $action->markDone('Doplatek uhrazen.');

            return true;
        }

        return $this->guestMessages->remindAboutBalance($action);
    }

    /**
     * Ubyport: nahlášeno → hotovo; jinak jednou upozorni ubytovatele, že cizinec
     * čeká na nahlášení (guard přes payload, ať cron neupozorňuje opakovaně).
     */
    private function handleUbyport(ReservationAction $action): bool
    {
        if ($action->getReservation()->getUbyportReport()->getExportedAt() !== null) {
            $action->markDone('Host nahlášen na Ubyport.');

            return true;
        }

        $payload = $action->getPayload() ?? [];
        if (($payload['owner_notified'] ?? false) === true) {
            return false;
        }

        // Guard nastavíme jen když se notifikace opravdu zařadila — jinak by při
        // zatím nenastaveném příjemci upozornění „propadlo" a už se neopakovalo.
        if (!$this->notifier->notify(OwnerNotificationType::UBYPORT_DUE, $action->getReservation())) {
            return false;
        }
        $action->setPayload($payload + ['owner_notified' => true]);

        return true;
    }

    /**
     * Je zpráva po okně platnosti?
     *  - pre-arrival: do příjezdu hosta,
     *  - post-stay:   do 3 dnů po odjezdu,
     *  - custom:      do 3 dnů po naplánovaném termínu (backstop).
     */
    private function isStale(ReservationAction $action, \DateTimeImmutable $now): bool
    {
        $reservation = $action->getReservation();
        $deadline = match ($action->getType()) {
            ActionType::RESERVATION_REQUEST_MESSAGE, ActionType::PRE_ARRIVAL_MESSAGE => $reservation->getCheckIn(),
            ActionType::POST_STAY_MESSAGE => ($reservation->getCheckOut() ?? $reservation->getCheckIn())->modify('+3 days'),
            default => $action->getScheduledFor()->modify('+3 days'),
        };

        return $now > $deadline;
    }

    /** Vystavená doplatková faktura akci uzavře — ať už jí nadešel čas, nebo ji zavírá událost. */
    private function resolveFinalInvoice(ReservationAction $action): bool
    {
        return $this->resolveIf(
            $action,
            $this->finalInvoiceIssued($action->getReservation()),
            'Doplatková faktura vystavena.',
        );
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
