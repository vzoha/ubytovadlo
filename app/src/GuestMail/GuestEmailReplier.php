<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\GuestMail;

use App\Email\GuestInbox\MailAddress;
use App\Entity\GuestEmail;
use App\Enum\EmailDirection;
use App\Mail\GuestEmailFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Odpověď na e-mail hosta odeslaná z aplikace: jde do stejného vlákna
 * (In-Reply-To, References), uloží se do korespondence a dotaz označí
 * jako zodpovězený. Pošta schránky hostů ji neuvidí v odeslaných, proto
 * se ukládá rovnou tady.
 */
class GuestEmailReplier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly GuestEmailFactory $emails,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws \InvalidArgumentException   když na e-mail odpovědět nejde nebo je text prázdný
     * @throws TransportExceptionInterface když odeslání selže — nic se neuloží
     */
    public function reply(GuestEmail $original, string $subject, string $text): GuestEmail
    {
        $to = $original->getCounterpartEmail();
        if (!$original->isReplyable() || $to === null) {
            throw new \InvalidArgumentException('Odpovědět jde jen na e-mail od hosta.');
        }
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Text odpovědi je prázdný.');
        }
        $subject = trim($subject);
        if ($subject === '') {
            $subject = $original->replySubject();
        }

        $messageId = $this->emails->newMessageId();
        // Dřívější zprávy vlákna a nakonec ta, na kterou se odpovídá.
        $threadIds = array_values(array_unique([...$original->getThreadIds(), $original->getMessageId()]));
        $message = $this->emails->create($to, $subject, $messageId)->text($text);
        $headers = $message->getHeaders();
        $headers->addIdHeader('In-Reply-To', $original->getMessageId());
        $headers->addIdHeader('References', $threadIds);

        $this->mailer->send($message);

        $reply = new GuestEmail(
            direction: EmailDirection::OUTBOUND,
            messageId: $messageId,
            counterpart: new MailAddress($original->getCounterpartName(), $to),
            subject: $subject,
            sentAt: $this->clock->now(),
            body: $text,
            threadIds: $threadIds,
        );
        $reply->completeMatch($original->getCustomer(), $original->getReservation());
        $original->markAnswered();
        $this->em->persist($reply);
        $this->em->flush();

        return $reply;
    }
}
