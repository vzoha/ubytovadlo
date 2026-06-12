<?php

declare(strict_types=1);

namespace App\Service\Electricity;

use App\Entity\Reservation;
use App\Repository\ElectricityTariffRepository;

/**
 * Spočítá orientační náklad za spotřebovanou elektřinu rezervace
 * podle tarifu platného k datu odjezdu. Hostovi to neúčtujeme — slouží
 * jen pro provozní přehled (kolik nás který pobyt stál).
 */
final class ElectricityCostCalculator
{
    public function __construct(private readonly ElectricityTariffRepository $tariffs)
    {
    }

    public function cost(Reservation $r): ?ElectricityCost
    {
        if ($r->getVtKwh() === null && $r->getNtKwh() === null) {
            return null;
        }
        $forDate = $r->getCheckOut() ?? $r->getCheckIn();
        $tariff = $this->tariffs->findForDate($forDate);
        if ($tariff === null) {
            return null;
        }
        $vtRate = (float) $tariff->getVtRate();
        $ntRate = (float) $tariff->getNtRate();
        $vtCzk = $vtRate * (float) ($r->getVtKwh() ?? 0);
        $ntCzk = $ntRate * (float) ($r->getNtKwh() ?? 0);

        return new ElectricityCost(
            vtCzk: $vtCzk,
            ntCzk: $ntCzk,
            totalCzk: $vtCzk + $ntCzk,
            vtRate: $vtRate,
            ntRate: $ntRate,
        );
    }
}
