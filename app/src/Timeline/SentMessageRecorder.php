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
use App\Entity\ReservationNote;
use App\Entity\User;
use App\Enum\NoteType;
use App\Enum\ShareChannel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Zpráva hostovi poslaná mimo poštu aplikace (WhatsApp, SMS, chat portálu)
 * jako záznam „Zpráva" na časové ose rezervace. Zapisuje se okamžik, kdy
 * ubytovatel zprávu otevřel v aplikaci — samotné odeslání appka nevidí.
 */
final class SentMessageRecorder
{
    /** Název šablony je krátký; delší vstup je chyba nebo podvrh. */
    private const MAX_LABEL = 120;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    /** Záznam se uloží při nejbližším flush. */
    public function record(Reservation $reservation, ShareChannel $channel, string $label): void
    {
        $label = mb_substr(trim($label), 0, self::MAX_LABEL);
        $note = new ReservationNote($reservation, NoteType::ZPRAVA, $label !== '' ? $channel->noteLabel() . ': ' . $label : $channel->noteLabel());

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $note->setAuthor($user);
        }

        $this->em->persist($note);
    }
}
