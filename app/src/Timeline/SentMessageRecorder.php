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
use App\Entity\ReservationNote;
use App\Entity\User;
use App\Enum\ActionDelivery;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use App\Enum\MessageKind;
use App\Enum\NoteType;
use App\Enum\ShareChannel;
use App\Repository\ReservationActionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Zpráva hostovi poslaná mimo poštu aplikace (WhatsApp, SMS, chat portálu).
 * Zpráva, která zastupuje naplánovanou automatickou zprávu, tu akci uzavře —
 * e-mail stejného obsahu pak hostovi neodejde a na ose zůstane jedna položka.
 * Jinak vznikne záznam „Zpráva"; když automatická zpráva už odešla, záznam se
 * k ní připojí. Zapisuje se okamžik, kdy ubytovatel zprávu otevřel v aplikaci —
 * samotné odeslání appka nevidí.
 */
final class SentMessageRecorder
{
    /** Název šablony je krátký; delší vstup je chyba nebo podvrh. */
    private const MAX_LABEL = 120;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ReservationActionRepository $actions,
    ) {
    }

    /**
     * Změny se uloží při nejbližším flush.
     *
     * @param MessageKind|null       $kind   druh automatické zprávy, kterou odeslaný text zastupuje
     * @param ReservationAction|null $action konkrétní akce z osy, jejíž text se odeslal (má přednost před druhem)
     *
     * @return bool true, když zpráva uzavřela naplánovanou akci
     */
    public function record(Reservation $reservation, ShareChannel $channel, string $label, ?MessageKind $kind = null, ?ReservationAction $action = null): bool
    {
        // Z chatu appka odeslání nevidí — akci tam uzavře ubytovatel sám.
        $type = $channel === ShareChannel::COPY ? null : ($action?->getType() ?? $kind?->actionType());

        $open = $type === null ? [] : $this->openActions($reservation, $type, $action);
        foreach ($open as $openAction) {
            $openAction->markDone($channel->sentResult(), ActionDelivery::MANUAL);
        }
        if ($open !== []) {
            return true;
        }

        $note = $this->note($reservation, $channel, $label);
        $note->setAction($type === null ? null : $this->doneAction($reservation, $type, $action));
        $this->em->persist($note);

        return false;
    }

    /** @return list<ReservationAction> */
    private function openActions(Reservation $reservation, ActionType $type, ?ReservationAction $action): array
    {
        if ($action !== null) {
            return $action->getStatus() === ActionStatus::PLANNED ? [$action] : [];
        }

        return array_values(array_filter(
            $this->actions->findOpenForReservation($reservation),
            static fn (ReservationAction $open): bool => $open->getType() === $type,
        ));
    }

    /** Akce, která zprávu už vyřídila — poslední z daného druhu. */
    private function doneAction(Reservation $reservation, ActionType $type, ?ReservationAction $action): ?ReservationAction
    {
        $candidates = $action !== null ? [$action] : $this->actions->findForReservation($reservation);
        $done = null;
        foreach ($candidates as $candidate) {
            if ($candidate->getType() === $type && $candidate->getStatus() === ActionStatus::DONE) {
                $done = $candidate;
            }
        }

        return $done;
    }

    private function note(Reservation $reservation, ShareChannel $channel, string $label): ReservationNote
    {
        $label = mb_substr(trim($label), 0, self::MAX_LABEL);
        $note = new ReservationNote($reservation, NoteType::ZPRAVA, $label !== '' ? $channel->noteLabel() . ': ' . $label : $channel->noteLabel());

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $note->setAuthor($user);
        }

        return $note;
    }
}
