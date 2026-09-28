<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Invoice\DepositPaymentBuilder;
use App\Invoice\PaymentQrLinks;
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
 * Autorizace = podpis adresy (PaymentQrLinks) vázaný na jednu rezervaci nebo
 * fakturu; bez platby, bez IBANu nebo se špatným podpisem → 404.
 */
final class QrController extends AbstractController
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly DepositPaymentBuilder $deposits,
        private readonly InvoiceRepository $invoices,
        private readonly PaymentQrLinks $links,
    ) {
    }

    #[Route('/qr/rezervace/{id}/{signature}.png', name: 'qr_deposit', methods: ['GET'], requirements: ['id' => '\d+', 'signature' => '[a-f0-9]{64}'])]
    public function deposit(int $id, string $signature): Response
    {
        if (!$this->links->isValidDeposit($id, $signature)) {
            throw $this->createNotFoundException();
        }

        return $this->depositPng($this->reservations->find($id));
    }

    #[Route('/qr/faktura/{id}/{signature}.png', name: 'qr_invoice', methods: ['GET'], requirements: ['id' => '\d+', 'signature' => '[a-f0-9]{64}'])]
    public function invoice(int $id, string $signature): Response
    {
        if (!$this->links->isValidInvoice($id, $signature)) {
            throw $this->createNotFoundException();
        }

        return $this->invoicePng($this->invoices->find($id));
    }

    /** Adresa podle check-in tokenu — obrázky v e-mailech odeslaných před zavedením podpisu. */
    #[Route('/qr/rezervace/{token}.png', name: 'qr_deposit_by_token', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function depositByToken(string $token): Response
    {
        return $this->depositPng($this->reservations->findOneBy(['checkinToken' => $token]));
    }

    /**
     * Adresa podle check-in tokenu — obrázky v e-mailech odeslaných před zavedením
     * podpisu. Faktura musí patřit rezervaci tokenu, jinak by šlo cizí doklad
     * uhádnout pořadovým ID.
     */
    #[Route('/qr/faktura/{token}/{id}.png', name: 'qr_invoice_by_token', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}', 'id' => '\d+'])]
    public function invoiceByToken(string $token, int $id): Response
    {
        $invoice = $this->invoices->find($id);
        if ($invoice !== null && !hash_equals((string) $invoice->getReservation()->getCheckinToken(), $token)) {
            $invoice = null;
        }

        return $this->invoicePng($invoice);
    }

    private function depositPng(?Reservation $reservation): Response
    {
        $spayd = $reservation === null ? null : $this->deposits->forReservation($reservation)?->spayd;
        if ($spayd === null) {
            throw $this->createNotFoundException();
        }

        return $this->png($spayd);
    }

    private function invoicePng(?Invoice $invoice): Response
    {
        $payload = $invoice?->getQrPayload();
        if ($payload === null) {
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
