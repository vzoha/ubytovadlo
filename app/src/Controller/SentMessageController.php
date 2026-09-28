<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Concern\ChecksCsrf;
use App\Entity\Reservation;
use App\Enum\ShareChannel;
use App\Timeline\SentMessageRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Zápis rychlé zprávy, kterou prohlížeč otevřel ve WhatsAppu či SMS nebo
 * zkopíroval do chatu, na časovou osu. Volá se na pozadí (sendBeacon).
 */
final class SentMessageController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly SentMessageRecorder $recorder,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/reservation/{id}/odeslana-zprava', name: 'reservation_sent_message', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function record(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'sent-message-' . $reservation->getId());

        $channel = ShareChannel::tryFrom((string) $request->request->get('channel'));
        if ($channel === null) {
            return new Response(null, Response::HTTP_BAD_REQUEST);
        }

        $this->recorder->record($reservation, $channel, (string) $request->request->get('label'));
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
