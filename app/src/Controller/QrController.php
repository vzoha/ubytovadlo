<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Invoice\DepositPaymentBuilder;
use App\Repository\ReservationRepository;
use Mpdf\QrCode\Output\Png;
use Mpdf\QrCode\QrCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Veřejný QR kód pro platbu zálohy — vloží se jako obrázek do e-mailu se žádostí
 * o zálohu (mailoví klienti nezobrazí data: URI, potřebují URL). Autorizace =
 * unikátní check-in token rezervace v URL; bez zálohy nebo bez IBANu → 404.
 */
final class QrController extends AbstractController
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly DepositPaymentBuilder $deposits,
    ) {
    }

    #[Route('/qr/rezervace/{token}.png', name: 'qr_deposit', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function deposit(string $token): Response
    {
        $reservation = $this->reservations->findOneBy(['checkinToken' => $token]);
        if ($reservation === null) {
            throw $this->createNotFoundException();
        }

        $deposit = $this->deposits->forReservation($reservation);
        if ($deposit === null || $deposit->spayd === null) {
            throw $this->createNotFoundException();
        }

        $png = (new Png())->output(new QrCode($deposit->spayd, 'M'), 300);

        return new Response($png, Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
