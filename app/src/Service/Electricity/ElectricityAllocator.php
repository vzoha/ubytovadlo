<?php

declare(strict_types=1);

namespace App\Service\Electricity;

use App\Entity\ElectricityReading;
use App\Enum\ElectricitySource;
use App\Repository\ElectricityReadingRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rozpočítá spotřebu mezi dvěma sousedními odečty mezi rezervace v intervalu.
 * Váha = SeasonalProfile (kWh/noc relativně k celoročnímu mediánu) přes všechny
 * noci pobytu. Idempotentní — přepisuje jen ALLOCATED, MEASURED nesahá.
 */
final class ElectricityAllocator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ElectricityReadingRepository $readings,
        private readonly ReservationRepository $reservations,
    ) {
    }

    public function rebalanceAll(): AllocationStats
    {
        $readings = $this->readings->findAllOrdered();
        $stats = new AllocationStats();

        for ($i = 0, $n = count($readings) - 1; $i < $n; $i++) {
            $this->accumulate($stats, $this->allocateInterval($readings[$i], $readings[$i + 1]));
        }

        $this->em->flush();

        return $stats;
    }

    /**
     * Přepočítá jen intervaly dotčené odečtem na daném datu. Pokud odečet
     * existuje, jde o interval prev→aktuální + aktuální→next (≤ 2 intervaly);
     * po smazání odečtu se spojí sousedi do prev→next.
     */
    public function rebalanceAround(\DateTimeImmutable $date): AllocationStats
    {
        $at = $this->readings->findOnDate($date);
        $prev = $this->readings->findPrevious($date);
        $next = $this->readings->findNext($date);
        $stats = new AllocationStats();

        if ($at !== null) {
            if ($prev !== null) {
                $this->accumulate($stats, $this->allocateInterval($prev, $at));
            }
            if ($next !== null) {
                $this->accumulate($stats, $this->allocateInterval($at, $next));
            }
        } elseif ($prev !== null && $next !== null) {
            $this->accumulate($stats, $this->allocateInterval($prev, $next));
        }

        $this->em->flush();

        return $stats;
    }

    private function accumulate(AllocationStats $stats, AllocationStats $delta): void
    {
        $stats->intervals++;
        $stats->reservations += $delta->reservations;
        $stats->skippedMeasured += $delta->skippedMeasured;
    }

    public function allocateInterval(ElectricityReading $from, ElectricityReading $to): AllocationStats
    {
        $totalVt = $to->getVtMeter() - $from->getVtMeter();
        $totalNt = $to->getNtMeter() - $from->getNtMeter();
        if ($totalVt < 0 || $totalNt < 0) {
            throw new \LogicException(sprintf('Záporná spotřeba mezi odečty %s a %s — pravděpodobně přehozený stav elektroměru.', $from->getReadAt()->format('Y-m-d'), $to->getReadAt()->format('Y-m-d')));
        }

        $stays = $this->reservations->findInInterval($from->getReadAt(), $to->getReadAt());

        $weighted = [];
        $totalWeight = 0.0;
        $skippedMeasured = 0;

        foreach ($stays as $r) {
            if ($r->getElectricitySource() === ElectricitySource::MEASURED) {
                $skippedMeasured++;
                continue;
            }
            $checkOut = $r->getCheckOut();
            if ($checkOut === null) {
                continue;
            }
            $w = SeasonalProfile::weightForStay($r->getCheckIn(), $checkOut, $r->getGuestsTotal());
            if ($w <= 0) {
                continue;
            }
            $weighted[] = ['r' => $r, 'w' => $w];
            $totalWeight += $w;
        }

        if ($totalWeight <= 0 || $weighted === []) {
            return new AllocationStats(skippedMeasured: $skippedMeasured);
        }

        // Proporční rozdělení s opravou zaokrouhlení — poslednímu připíšeme zbytek,
        // aby součet alokovaných kWh přesně odpovídal totalu z odečtů.
        $allocatedVt = 0;
        $allocatedNt = 0;
        $lastIdx = count($weighted) - 1;
        foreach ($weighted as $idx => $item) {
            if ($idx === $lastIdx) {
                $vt = $totalVt - $allocatedVt;
                $nt = $totalNt - $allocatedNt;
            } else {
                $share = $item['w'] / $totalWeight;
                $vt = (int) round($totalVt * $share);
                $nt = (int) round($totalNt * $share);
                $allocatedVt += $vt;
                $allocatedNt += $nt;
            }
            $item['r']->setVtKwh($vt);
            $item['r']->setNtKwh($nt);
            $item['r']->setElectricitySource(ElectricitySource::ALLOCATED);
        }

        return new AllocationStats(reservations: count($weighted), skippedMeasured: $skippedMeasured);
    }
}
