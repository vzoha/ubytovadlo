<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Timeline;

use App\Entity\MessageTemplate;
use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Enum\ActionType;
use App\Enum\GuestMessageStatus;
use App\Enum\InvoiceType;
use App\Enum\MessageKind;
use App\Enum\OwnerNotificationType;
use App\Enum\PaymentStatus;
use App\Enum\SendMode;
use App\Invoice\BalanceCalculator;
use App\Invoice\PaymentStatusResolver;
use App\Mail\GuestMessageSender;
use App\Mail\MessageTemplateProvider;
use App\Notification\OwnerNotifier;
use App\Repository\InvoiceRepository;

/**
 * Vyhodnotí naplánovanou akci, které nadešel čas:
 *  - Zprávy hostům (pre-arrival / post-stay / custom) se odešlou e-mailem, pokud
 *    je akce v okně platnosti, šablona zapnutá a host má e-mail. Mimo okno se
 *    označí SKIPPED, ať se prošlá zpráva nepošle zpětně.
 *  - Připomínka doplatku se self-resolvuje, když je doplatek uhrazen; jinak (a
 *    je-li šablona zapnutá a host má e-mail) pošle hostovi jednu připomínku.
 *  - Ostatní připomínky (doplatková faktura, Ubyport) se self-resolvují podle
 *    stavu rezervace, CUSTOM_REMINDER řeší majitelka ručně.
 */
class ReservationActionExecutor
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly BalanceCalculator $balance,
        private readonly GuestMessageSender $sender,
        private readonly MessageTemplateProvider $templates,
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

        $kind = MessageKind::fromActionType($action->getType());
        if ($kind === null) {
            return false;
        }

        // Custom je ruční, pošle se vždy. Ostatní ctí režim: vypnutá se přeskočí,
        // ruční zůstane na ose k odeslání tlačítkem (cron ji sám neodešle).
        if ($kind !== MessageKind::CUSTOM) {
            $mode = $this->templates->for($kind)->getMode();
            if ($mode === SendMode::OFF) {
                $action->markSkipped('Zpráva je vypnutá — neodesláno.');

                return true;
            }
            if ($mode === SendMode::DRAFT) {
                return false;
            }
        }

        if (!$this->sender->canSend($action->getReservation())) {
            $action->markSkipped('Host nemá e-mail — zpráva neodeslána.');

            return true;
        }

        return $this->dispatch($action, $kind, $this->customOverride($action, $kind));
    }

    /**
     * Ruční odeslání zprávy z časové osy (tlačítko u návrhu) — přeskočí režim
     * i okno platnosti, odešle rovnou. Respektuje jen chybějící e-mail hosta.
     *
     * @return bool true, pokud akce změnila stav (a je třeba flush)
     */
    public function sendNow(ReservationAction $action): bool
    {
        $kind = MessageKind::fromActionType($action->getType());
        if ($kind === null) {
            return false;
        }

        if (!$this->sender->canSend($action->getReservation())) {
            $action->markSkipped('Host nemá e-mail — zpráva neodeslána.');

            return true;
        }

        return $this->dispatch($action, $kind, $this->customOverride($action, $kind));
    }

    /**
     * Připomínka doplatku: uhrazeno → hotovo; jinak jedna připomínka hostovi
     * (jen je-li šablona zapnutá a host má e-mail, jinak zůstane k ručnímu řešení).
     */
    private function handleBalanceReminder(ReservationAction $action): bool
    {
        $reservation = $action->getReservation();
        if ($this->balanceSettled($reservation)) {
            $action->markDone('Doplatek uhrazen.');

            return true;
        }

        // Připomínku pošle sám jen v režimu AUTO; ruční/vypnuto nechá akci
        // otevřenou k ručnímu vyřízení.
        if ($this->templates->for(MessageKind::BALANCE_REMINDER)->getMode() !== SendMode::AUTO
            || !$this->sender->canSend($reservation)) {
            return false;
        }

        return $this->dispatch($action, MessageKind::BALANCE_REMINDER, null);
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
     * Odešle zprávu a podle výsledku označí akci DONE/FAILED. Při selhání navíc
     * upozorní ubytovatele (akce zůstane FAILED, takže se notifikace nespamuje).
     */
    private function dispatch(ReservationAction $action, MessageKind $kind, ?MessageTemplate $override): bool
    {
        $message = $this->sender->send($action->getReservation(), $kind, [], [], $override);

        if ($message->getStatus() === GuestMessageStatus::SENT) {
            $action->markDone(sprintf('Zpráva odeslána hostovi (%s).', $message->getToEmail()));
        } else {
            $action->markFailed('Odeslání selhalo: ' . (string) $message->getError());
            $this->notifier->notify(OwnerNotificationType::GUEST_MESSAGE_FAILED, $action->getReservation(), [
                'kind' => $kind->label(),
                'error' => (string) $message->getError(),
            ]);
        }

        return true;
    }

    /** Pro custom zprávu složí ad-hoc šablonu z volného textu v payloadu. */
    private function customOverride(ReservationAction $action, MessageKind $kind): ?MessageTemplate
    {
        if ($kind !== MessageKind::CUSTOM) {
            return null;
        }
        $payload = $action->getPayload() ?? [];
        $text = trim((string) ($payload['text'] ?? ''));
        if ($text === '') {
            return null;
        }

        return new MessageTemplate(MessageKind::CUSTOM, $this->templates->for(MessageKind::CUSTOM)->getSubject(), $text);
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
