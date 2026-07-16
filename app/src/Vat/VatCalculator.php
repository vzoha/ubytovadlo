<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Vat;

use App\Entity\Embeddable\VatReverseCharge;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Formatting\Money;

/**
 * Spočítá DPH pro reverse charge nad provizí OTA (identifikovaná osoba, §6h ZDPH).
 *
 * Pravidla:
 * - DUZP = den poskytnutí služby. Pro per-rezervační provizi je to den check-outu
 *   (kdy je rezervace fakticky dokončená a Booking/Airbnb si na ni účtuje provizi).
 *   To je konzervativnější výklad než „datum vystavení faktury" — DPH se odvede
 *   v měsíci odjezdu, tedy ve stejném měsíci, do kterého spadá Booking měsíční
 *   faktura (Booking sám účtuje podle termínu odjezdu).
 * - U Airbnb (commission v CZK) je base = commission přímo, kurz ČNB se nepoužívá.
 * - U Booking (commission v EUR) převedeme kurzem ČNB platným k DUZP. ČNB API
 *   samo vrátí kurz předchozího pracovního dne pro víkendy/svátky.
 * - Sazba DPH 21 %, bez nároku na odpočet.
 */
class VatCalculator
{
    public const VAT_RATE = 0.21;

    public function __construct(private readonly CnbExchangeRateClient $cnb)
    {
    }

    /**
     * Spočítá a uloží DPH pole na rezervaci. Nic nezmění, pokud rezervace
     * nemá vyplněnou provizi nebo check-out. Vrátí true pokud něco přepočítal.
     */
    public function recalculate(Reservation $reservation): bool
    {
        if ($reservation->getCommissionAmount() === null) {
            return false;
        }
        $checkOut = $reservation->getCheckOut();
        if ($checkOut === null) {
            return false;
        }

        $duzp = $this->resolveDuzp($reservation, $checkOut);
        $commission = (float) $reservation->getCommissionAmount();
        $commissionCurrency = $reservation->getCommissionCurrency() ?? 'CZK';

        if ($commissionCurrency === 'CZK') {
            $baseCzk = $commission;
            $cnbRate = null;
            $cnbRateDate = null;
        } else {
            $rate = $this->cnb->getRate($commissionCurrency, $duzp);
            $baseCzk = $commission * $rate->rate;
            $cnbRate = number_format($rate->rate, 8, '.', '');
            $cnbRateDate = $rate->validFor;
        }

        $reservation->setVatReverseCharge(new VatReverseCharge(
            duzp: $duzp,
            cnbRate: $cnbRate,
            cnbRateDate: $cnbRateDate,
            baseCzk: Money::normalize($baseCzk),
            amountCzk: Money::normalize($baseCzk * self::VAT_RATE),
        ));

        return true;
    }

    /**
     * Pro Booking je DUZP poslední den měsíce, ve kterém spadá check-out
     * (matchuje s tím, jak Booking sám měsíční faktury sestavuje — vše s odjezdem
     * v měsíci jde do jedné faktury). Pro Airbnb je DUZP samotný check-out.
     */
    private function resolveDuzp(Reservation $reservation, \DateTimeImmutable $checkOut): \DateTimeImmutable
    {
        if ($reservation->getChannel() === Channel::BOOKING) {
            return $checkOut->modify('last day of this month');
        }

        return $checkOut;
    }
}
