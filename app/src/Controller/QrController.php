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
use App\Repository\InvoiceRepository;
use App\Repository\ReservationRepository;
use Mpdf\QrCode\Output\Png;
use Mpdf\QrCode\QrCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Veřejné QR kódy pro platbu — vloží se jako obrázek do e-mailu se žádostí
 * o zálohu nebo s fakturou (mailoví klienti nezobrazí data: URI, potřebují URL).
 * Autorizace = unikátní check-in token rezervace v URL; bez platby nebo bez
 * IBANu → 404.
 */
final class QrController extends AbstractController
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly DepositPaymentBuilder $deposits,
        private readonly InvoiceRepository $invoices,
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

        return $this->png($deposit->spayd);
    }

    /**
     * QR kód k faktuře. Token patří rezervaci, faktura musí být její — jinak by
     * šlo cizí doklad uhádnout pořadovým ID.
     */
    #[Route('/qr/faktura/{token}/{id}.png', name: 'qr_invoice', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}', 'id' => '\d+'])]
    public function invoice(string $token, int $id): Response
    {
        $invoice = $this->invoices->find($id);
        $payload = $invoice?->getQrPayload();
        if ($invoice === null || $payload === null || $invoice->getReservation()->getCheckinToken() !== $token) {
            throw $this->createNotFoundException();
        }

        return $this->png($payload);
    }

    private function png(string $payload): Response
    {
        return new Response((new Png())->output(new QrCode($payload, 'M'), 300), Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
