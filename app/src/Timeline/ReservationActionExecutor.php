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
use App\Entity\ReservationAction;
use App\Enum\ActionType;
use App\Enum\GuestMessageStatus;
use App\Enum\InvoiceType;
use App\Enum\MessageKind;
use App\Invoice\BalanceCalculator;
use App\Mail\GuestMessageSender;
use App\Mail\MessageTemplateProvider;
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
            ActionType::BALANCE_REMINDER => $this->handleBalanceReminder($action),
            ActionType::UBYPORT_EXPORT => $this->resolveIf(
                $action,
                $reservation->getUbyportExportedAt() !== null,
                'Host nahlášen na Ubyport.',
            ),
            ActionType::PRE_ARRIVAL_MESSAGE,
            ActionType::POST_STAY_MESSAGE,
            ActionType::CUSTOM_MESSAGE => $this->handleGuestMessage($action, $now),
            // Ruční připomínky (CUSTOM_REMINDER) řeší majitelka sama.
            default => false,
        };
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

        // Auto zprávy ctí vypnutou šablonu; custom je ruční, pošle se vždy.
        if ($kind !== MessageKind::CUSTOM && !$this->templates->for($kind)->isEnabled()) {
            $action->markSkipped('Šablona zprávy je vypnutá — neodesláno.');

            return true;
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
        if ($this->balance->forReservation($reservation)?->isSettled() ?? false) {
            $action->markDone('Doplatek uhrazen.');

            return true;
        }

        if (!$this->templates->for(MessageKind::BALANCE_REMINDER)->isEnabled() || !$this->sender->canSend($reservation)) {
            return false;
        }

        return $this->dispatch($action, MessageKind::BALANCE_REMINDER, null);
    }

    /**
     * Odešle zprávu a podle výsledku označí akci DONE/FAILED.
     */
    private function dispatch(ReservationAction $action, MessageKind $kind, ?MessageTemplate $override): bool
    {
        $message = $this->sender->send($action->getReservation(), $kind, [], [], $override);

        if ($message->getStatus() === GuestMessageStatus::SENT) {
            $action->markDone(sprintf('Zpráva odeslána hostovi (%s).', $message->getToEmail()));
        } else {
            $action->markFailed('Odeslání selhalo: ' . (string) $message->getError());
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
            ActionType::PRE_ARRIVAL_MESSAGE => $reservation->getCheckIn(),
            ActionType::POST_STAY_MESSAGE => ($reservation->getCheckOut() ?? $reservation->getCheckIn())->modify('+3 days'),
            default => $action->getScheduledFor()->modify('+3 days'),
        };

        return $now > $deadline;
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
