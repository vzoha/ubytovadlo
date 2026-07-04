<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Ical\ICalendarWriter;
use App\Ical\IcalFeedToken;
use App\Repository\ReservationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Veřejný iCal feed obsazenosti pro OTA (Booking, Airbnb) — čtou ho anonymně
 * přes token v URL. Chrání jen token, ne login (feed stahuje stroj OTA).
 */
class IcalController extends AbstractController
{
    public function __construct(
        private readonly IcalFeedToken $feedToken,
        private readonly ReservationRepository $reservations,
        private readonly ICalendarWriter $writer,
    ) {
    }

    #[Route('/ical/{token}.ics', name: 'ical_feed', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function feed(string $token, Request $request): Response
    {
        if (!$this->feedToken->matches($token)) {
            throw $this->createNotFoundException();
        }

        // Blokujeme od začátku aktuálního měsíce dál — minulé pobyty už OTA neřeší.
        $from = new \DateTimeImmutable('first day of this month 00:00');
        $reservations = $this->reservations->findForAvailabilityExport($from);
        $body = $this->writer->build($reservations, $request->getHost());

        $response = new Response($body);
        $response->headers->set('Content-Type', 'text/calendar; charset=utf-8');
        $response->headers->set('Content-Disposition', 'inline; filename="obsazenost.ics"');

        return $response;
    }
}
