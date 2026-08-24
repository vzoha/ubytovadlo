<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Currency;

use App\Entity\Reservation;
use App\Enum\CzkRateSource;
use App\Repository\InvoiceRepository;
use App\Vat\CnbRate;

/**
 * Náhled ceny rezervace v korunách po rezervacích (dávkově pro seznam, bez N+1).
 * Týká se jen rezervací v cizí měně se známou cenou.
 *
 * Kurz se bere v pořadí od nejzávaznějšího: kurz vystavené faktury, pak ČNB kurz
 * uložený k rezervaci (přepočet provize k datu plnění), a nakonec dnešní kurz ČNB
 * jako orientace u rezervace, která svůj kurz ještě nemá.
 */
final class ReservationCzkPreviewResolver
{
    public function __construct(
        private readonly CurrencyConverter $converter,
        private readonly InvoiceRepository $invoices,
        private readonly DailyCnbRateProvider $dailyRate,
    ) {
    }

    /**
     * @param Reservation[] $reservations
     *
     * @return array<int, CzkPreview> klíč = ID rezervace; jen ty v cizí měně
     */
    public function batch(array $reservations, ?\DateTimeImmutable $today = null): array
    {
        $applicable = array_filter($reservations, fn (Reservation $r): bool => $this->applies($r));
        if ($applicable === []) {
            return [];
        }

        $today ??= new \DateTimeImmutable('today');
        $ids = array_map(static fn (Reservation $r): int => (int) $r->getId(), $applicable);
        $invoiceRates = $this->invoices->findExchangeRatesByReservations($ids);

        $out = [];
        foreach ($applicable as $reservation) {
            $id = (int) $reservation->getId();
            $preview = $this->resolve($reservation, $invoiceRates[$id] ?? null, $today);
            if ($preview !== null) {
                $out[$id] = $preview;
            }
        }

        return $out;
    }

    /**
     * @param array{currency: ?string, rate: string, date: ?\DateTimeImmutable}|null $invoiceRate
     */
    private function resolve(Reservation $reservation, ?array $invoiceRate, \DateTimeImmutable $today): ?CzkPreview
    {
        $currency = $reservation->getPriceCurrency();

        if ($invoiceRate !== null && $invoiceRate['currency'] === $currency) {
            return $this->preview($reservation, $invoiceRate['rate'], $invoiceRate['date'], CzkRateSource::INVOICE);
        }

        // Uložený kurz vznikl přepočtem provize — pro cenu platí jen ve stejné měně.
        $vat = $reservation->getVatReverseCharge();
        if ($vat->getCnbRate() !== null && $reservation->getCommissionCurrency() === $currency) {
            return $this->preview($reservation, $vat->getCnbRate(), $vat->getCnbRateDate(), CzkRateSource::RESERVATION);
        }

        $daily = $this->dailyRate->rate($currency, $today);

        return $daily !== null
            ? $this->preview($reservation, self::asDecimal($daily), $daily->validFor, CzkRateSource::DAILY)
            : null;
    }

    private function preview(Reservation $reservation, string $rate, ?\DateTimeImmutable $rateDate, CzkRateSource $source): ?CzkPreview
    {
        $amount = $this->converter->toCzk($reservation->getPriceTotal(), $reservation->getPriceCurrency(), $rate);

        return $amount !== null ? new CzkPreview($amount, $rate, $rateDate, $source) : null;
    }

    private function applies(Reservation $reservation): bool
    {
        return $reservation->getPriceTotal() !== null
            && $reservation->getPriceCurrency() !== 'CZK';
    }

    private static function asDecimal(CnbRate $rate): string
    {
        return number_format($rate->rate, 8, '.', '');
    }
}
