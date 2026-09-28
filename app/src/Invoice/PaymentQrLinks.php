<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

use App\Security\PublicLinkSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Veřejné adresy QR kódů pro platbu (obrázek v e-mailu hostovi). Každá adresa
 * je podepsaná pro jednu rezervaci nebo fakturu a nic jiného neotevře.
 */
final class PaymentQrLinks
{
    private const DEPOSIT = 'qr-deposit';
    private const INVOICE = 'qr-invoice';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly PublicLinkSigner $signer,
    ) {
    }

    public function depositUrl(int $reservationId): string
    {
        return $this->url('qr_deposit', self::DEPOSIT, $reservationId);
    }

    public function invoiceUrl(int $invoiceId): string
    {
        return $this->url('qr_invoice', self::INVOICE, $invoiceId);
    }

    public function isValidDeposit(int $reservationId, string $signature): bool
    {
        return $this->signer->verify(self::DEPOSIT, $reservationId, $signature);
    }

    public function isValidInvoice(int $invoiceId, string $signature): bool
    {
        return $this->signer->verify(self::INVOICE, $invoiceId, $signature);
    }

    private function url(string $route, string $resource, int $id): string
    {
        return $this->urlGenerator->generate(
            $route,
            ['id' => $id, 'signature' => $this->signer->sign($resource, $id)],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
