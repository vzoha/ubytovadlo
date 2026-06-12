<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Vat;

use Smalot\PdfParser\Parser as PdfParser;

class BookingInvoiceParser
{
    // České formátování: "137,56" nebo "1 229,93" (mezera jako tisícový oddělovač,
    // čárka jako desetinný). Předpokládáme normalizovaný whitespace (single space).
    private const AMOUNT_PATTERN = '\\d{1,3}(?: \\d{3})*,\\d{2}';

    public function __construct(private readonly PdfParser $pdfParser = new PdfParser())
    {
    }

    public function parseFile(string $path): BookingInvoiceData
    {
        return $this->parseText($this->pdfParser->parseFile($path)->getText());
    }

    public function parseContent(string $pdfContent): BookingInvoiceData
    {
        return $this->parseText($this->pdfParser->parseContent($pdfContent)->getText());
    }

    public function parseText(string $text): BookingInvoiceData
    {
        // Booking PDF dává NBSP (U+00A0) i mezi slovy ("za<NBSP>platební služby"),
        // sjednotíme všechny whitespace runs na jednu mezeru — regexy jsou pak triviální.
        $text = preg_replace('/[\\s\\x{00A0}]+/u', ' ', $text) ?? $text;

        $invoiceNumber = $this->matchRequired('/\\bČíslo faktury:\\s*(?<value>\\d{6,})/u', $text, 'invoice number');
        $issuedAt = $this->parseDate($this->matchRequired('/\\bDatum:\\s*(?<value>\\d{2}\\/\\d{2}\\/\\d{4})/u', $text, 'issue date'));

        if (!preg_match('/Období:\\s*(?<from>\\d{2}\\/\\d{2}\\/\\d{4})\\s*-\\s*(?<to>\\d{2}\\/\\d{2}\\/\\d{4})/u', $text, $m)) {
            throw new \RuntimeException('Cannot find period in Booking invoice text');
        }
        $periodFrom = $this->parseDate($m['from']);
        $periodTo = $this->parseDate($m['to']);

        $amount = self::AMOUNT_PATTERN;

        // "Rezervace EUR 826,20 EUR 123,93" — prodej pokojů + provize
        if (!preg_match("/Rezervace\\s+(?<currency>[A-Z]{3})\\s+(?<sales>{$amount})\\s+[A-Z]{3}\\s+(?<commission>{$amount})/u", $text, $m)) {
            throw new \RuntimeException('Cannot find Rezervace / Provize line in Booking invoice');
        }
        $currency = $m['currency'];
        $roomSales = $this->parseAmount($m['sales']);
        $commission = $this->parseAmount($m['commission']);

        $paymentFee = '0.00';
        if (preg_match("/Poplatek za platební služby\\s+[A-Z]{3}\\s+(?<value>{$amount})/u", $text, $m)) {
            $paymentFee = $this->parseAmount($m['value']);
        }

        $totalPayable = $this->parseAmount($this->matchRequired("/K zaplacení celkem\\s+[A-Z]{3}\\s+(?<value>{$amount})/u", $text, 'total payable'));

        $bookingRate = null;
        if (preg_match('/směnného kurzu\\s+(?<value>[\\d,]+)\\s*CZK/u', $text, $m)) {
            $bookingRate = str_replace(',', '.', $m['value']);
        }

        return new BookingInvoiceData(
            invoiceNumber: $invoiceNumber,
            issuedAt: $issuedAt,
            periodFrom: $periodFrom,
            periodTo: $periodTo,
            currency: $currency,
            roomSales: $roomSales,
            commission: $commission,
            paymentFee: $paymentFee,
            totalPayable: $totalPayable,
            bookingExchangeRate: $bookingRate,
        );
    }

    private function matchRequired(string $pattern, string $text, string $what): string
    {
        if (!preg_match($pattern, $text, $m)) {
            throw new \RuntimeException(sprintf('Cannot find %s in Booking invoice', $what));
        }

        return trim($m['value']);
    }

    private function parseDate(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $value);
        if ($date === false) {
            throw new \RuntimeException(sprintf('Invalid date "%s"', $value));
        }

        return $date;
    }

    /**
     * "1 229,93" → "1229.93".  Normalizuje české tisícové oddělovače (mezery,
     * vč. non-breaking space U+00A0) a desetinou čárku.
     */
    private function parseAmount(string $raw): string
    {
        $cleaned = preg_replace('/[\\s\\x{00A0}]+/u', '', $raw) ?? '';
        $cleaned = str_replace(',', '.', $cleaned);
        if (!is_numeric($cleaned)) {
            throw new \RuntimeException(sprintf('Cannot parse amount "%s"', $raw));
        }

        return number_format((float) $cleaned, 2, '.', '');
    }
}
