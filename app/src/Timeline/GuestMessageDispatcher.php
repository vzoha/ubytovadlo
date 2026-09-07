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
use App\Enum\ActionDelivery;
use App\Enum\GuestMessageStatus;
use App\Enum\GuestMessaging;
use App\Enum\MessageKind;
use App\Enum\MessageOutlook;
use App\Enum\OwnerNotificationType;
use App\Enum\SendMode;
use App\Mail\ActionMessageResolver;
use App\Mail\GuestMessageDelivery;
use App\Mail\GuestMessageSender;
use App\Mail\MessageTemplateProvider;
use App\Notification\OwnerNotifier;

/**
 * Odeslání zprávy hostovi z akce na časové ose. Skládá dvě nastavení: režim
 * šablony (jestli vůbec a jestli sama) a cestu u prodejního kanálu (poštou,
 * nebo do chatu portálu). Do chatu vkládá text ubytovatelka, takže taková akce
 * na ose čeká; poštou odejde sama.
 */
class GuestMessageDispatcher
{
    public function __construct(
        private readonly GuestMessageSender $sender,
        private readonly GuestMessageDelivery $delivery,
        private readonly MessageTemplateProvider $templates,
        private readonly GuestMessageOutlookResolver $outlook,
        private readonly ActionMessageResolver $messages,
        private readonly OwnerNotifier $notifier,
    ) {
    }

    /**
     * Zpráva, které nadešel čas.
     *
     * @return bool true, pokud akce změnila stav (a je třeba flush)
     */
    public function dispatchDue(ReservationAction $action): bool
    {
        $kind = MessageKind::fromActionType($action->getType());
        $outlook = $this->outlook->forAction($action);
        if ($kind === null || $outlook === null) {
            return false;
        }

        // Co osa slibuje, to se stane: vypnutá zpráva se zavře bez odeslání,
        // čekající na ubytovatele zůstane otevřená k odeslání tlačítkem.
        if ($outlook === MessageOutlook::TEMPLATE_OFF) {
            $action->markSkipped('Zpráva je vypnutá — neodesláno.');

            return true;
        }
        if ($outlook === MessageOutlook::CHANNEL_SILENT) {
            $action->markSkipped('Kanál hostům zprávy neposílá.');

            return true;
        }
        if ($outlook->needsOwner()) {
            return false;
        }

        return $this->send($action, $kind, $this->messages->template($action, $kind));
    }

    /**
     * Ruční odeslání z časové osy (tlačítko u návrhu) — přeskočí režim i okno
     * platnosti. Zprávu vedenou do chatu portálu poštou neposílá.
     *
     * @return bool true, pokud akce změnila stav (a je třeba flush)
     */
    public function sendNow(ReservationAction $action): bool
    {
        $kind = MessageKind::fromActionType($action->getType());
        if ($kind === null) {
            return false;
        }

        if ($this->delivery->route($action->getReservation()) === GuestMessaging::CHAT) {
            $action->markSkipped('Zprávy hostům vedou do chatu portálu — poštou se neodesílají.');

            return true;
        }

        return $this->send($action, $kind, $this->messages->template($action, $kind));
    }

    /**
     * Připomínka doplatku odejde sama jen v režimu AUTO a poštou; jinak akce
     * zůstane otevřená k ručnímu vyřízení.
     *
     * @return bool true, pokud akce změnila stav (a je třeba flush)
     */
    public function remindAboutBalance(ReservationAction $action): bool
    {
        if ($this->templates->for(MessageKind::BALANCE_REMINDER)->getMode() !== SendMode::AUTO
            || $this->delivery->route($action->getReservation()) !== GuestMessaging::EMAIL) {
            return false;
        }

        return $this->send($action, MessageKind::BALANCE_REMINDER, null);
    }

    /**
     * Odešle zprávu a podle výsledku označí akci DONE/FAILED. Při selhání navíc
     * upozorní ubytovatele (akce zůstane FAILED, takže se notifikace nespamuje).
     */
    private function send(ReservationAction $action, MessageKind $kind, ?MessageTemplate $override): bool
    {
        $reservation = $action->getReservation();
        if (!$this->sender->canSend($reservation)) {
            $action->markSkipped('Host nemá e-mail — zpráva neodeslána.');

            return true;
        }

        $message = $this->sender->send($reservation, $kind, [], [], $override);

        if ($message->getStatus() === GuestMessageStatus::SENT) {
            $action->markDone(sprintf('Zpráva odeslána hostovi (%s).', $message->getToEmail()), ActionDelivery::EMAIL);

            return true;
        }

        $action->markFailed('Odeslání selhalo: ' . (string) $message->getError());
        $this->notifier->notify(OwnerNotificationType::GUEST_MESSAGE_FAILED, $reservation, [
            'kind' => $kind->label(),
            'error' => (string) $message->getError(),
        ]);

        return true;
    }
}
