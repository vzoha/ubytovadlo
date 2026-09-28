<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller\Concern;

use App\Entity\Reservation;
use App\Enum\ShareChannel;
use App\Mail\GuestPhoneLinks;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Hotový text zprávy hostovi: pro WhatsApp a SMS přesměrování do aplikace
 * s předvyplněným textem, pro chat portálu text jako JSON ke zkopírování.
 *
 * Pro controller dědící z `AbstractController`.
 */
trait RespondsWithGuestText
{
    /** Kanál z formuláře; neznámý = 400. */
    private function channel(Request $request): ShareChannel
    {
        return ShareChannel::tryFrom((string) $request->request->get('channel'))
            ?? throw new BadRequestHttpException('Neznámý kanál.');
    }

    private function guestTextResponse(Reservation $reservation, ShareChannel $channel, string $text): Response
    {
        if ($channel === ShareChannel::COPY) {
            return new JsonResponse(['text' => $text]);
        }

        $url = GuestPhoneLinks::compose($reservation, $channel, $text);
        if ($url === null) {
            $this->addFlash('danger', 'Rezervace nemá použitelný telefon hosta.');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        return new RedirectResponse($url, Response::HTTP_SEE_OTHER);
    }
}
