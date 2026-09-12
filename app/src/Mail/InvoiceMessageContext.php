<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Enum\InvoiceType;
use App\Formatting\GuestDate;
use App\Formatting\Money;
use App\Repository\InvoiceRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Hodnoty proměnných `invoice_*` pro zprávu, která veze fakturu. Čte je z
 * odesílaného dokladu, ne z rezervace — účet, variabilní symbol i splatnost
 * na faktuře se od zálohy liší.
 *
 * `invoice_payment_status` nese hotovou větu o úhradě (v jazyce hosta)
 * a `invoice_qr` je u zaplacené faktury prázdný, takže šablona vystačí
 * s dosazením proměnné a nepotřebuje podmínky.
 */
final class InvoiceMessageContext
{
    /** @var array<string, array{paid: string, due: string, unpaid: string}> */
    private const PAYMENT_STATUS = [
        'cs' => ['paid' => 'uhrazeno %s', 'due' => 'k úhradě do %s', 'unpaid' => 'k úhradě'],
        'en' => ['paid' => 'paid on %s', 'due' => 'due by %s', 'unpaid' => 'due'],
    ];

    /** Faktura, o které host mluví, když se řekne „vaše faktura" — pořadí hledání. */
    private const RESERVATION_INVOICE_TYPES = [InvoiceType::FINAL, InvoiceType::FULL];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly GuestLocaleResolver $guestLocale,
        private readonly InvoiceRepository $invoices,
    ) {
    }

    /**
     * Faktura rezervace pro zprávy, které ji nevezou v příloze (připomínka
     * doplatku). Rezervace bez faktury i neuložená rezervace (náhled) vrací
     * prázdno — proměnné `invoice_*` pak zůstanou prázdné.
     *
     * @return array<string, string> proměnná => hodnota
     */
    public function forReservation(Reservation $reservation): array
    {
        if ($reservation->getId() === null) {
            return [];
        }

        foreach (self::RESERVATION_INVOICE_TYPES as $type) {
            $invoice = $this->invoices->findFirstByReservationAndType($reservation, $type);
            if ($invoice !== null) {
                return $this->forInvoice($invoice);
            }
        }

        return [];
    }

    /** @return array<string, string> proměnná => hodnota */
    public function forInvoice(Invoice $invoice): array
    {
        $locale = $this->guestLocale->forReservation($invoice->getReservation());
        $paidAt = $invoice->getPaidAt();
        $dueAt = $invoice->getDueAt();

        return [
            'invoice_number' => $invoice->getNumber(),
            'invoice_total' => Money::format($invoice->getTotalAmount(), $invoice->getCurrency()),
            'invoice_payment_status' => $this->paymentStatus($locale, $paidAt, $dueAt),
            'invoice_due' => $paidAt === null && $dueAt !== null ? GuestDate::format($dueAt, $locale) : '',
            'invoice_bank_account' => $invoice->getBankAccount() ?? '',
            'invoice_variable_symbol' => $invoice->getDisplayVariableSymbol(),
            'invoice_qr' => $this->qr($invoice),
        ];
    }

    private function paymentStatus(string $locale, ?\DateTimeImmutable $paidAt, ?\DateTimeImmutable $dueAt): string
    {
        $texts = self::PAYMENT_STATUS[$locale] ?? self::PAYMENT_STATUS[MessageLocales::BASE];

        if ($paidAt !== null) {
            return sprintf($texts['paid'], GuestDate::format($paidAt, $locale));
        }

        return $dueAt !== null ? sprintf($texts['due'], GuestDate::format($dueAt, $locale)) : $texts['unpaid'];
    }

    /**
     * QR platba jako Markdownový obrázek na veřejný PNG endpoint (stejně jako
     * u zálohy — mailoví klienti nezobrazí data: URI). Prázdné u uhrazené
     * faktury, bez SPAYD payloadu i u neuloženého dokladu (náhled).
     */
    private function qr(Invoice $invoice): string
    {
        $id = $invoice->getId();
        $token = $invoice->getReservation()->getCheckinToken();
        if ($invoice->getPaidAt() !== null || $invoice->getQrPayload() === null || $id === null || $token === null) {
            return '';
        }

        $url = $this->urlGenerator->generate(
            'qr_invoice',
            ['token' => $token, 'id' => $id],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return sprintf('![QR platba faktury](%s)', $url);
    }
}
