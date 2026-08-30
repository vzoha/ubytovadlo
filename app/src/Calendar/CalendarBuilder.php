<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Calendar;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Formatting\CzechCalendar;
use App\Occupancy\OccupancyConflictFinder;
use App\Repository\CleaningRepository;
use App\Repository\RecurringTaskRepository;
use App\Repository\ReservationRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Složí podklad pro kalendář: pásy rezervací po jednotkách a pod nimi servisní
 * řádky (úklid po pobytu, hlídané termíny). Osa i mřížka kreslí týž objekt,
 * mřížka si ho jen nechá nakrájet po týdnech ({@see CalendarWeekSplitter}).
 */
final class CalendarBuilder
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly CleaningRepository $cleanings,
        private readonly RecurringTaskRepository $tasks,
        private readonly UnitProvider $units,
        private readonly OccupancyConflictFinder $conflictFinder,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function build(CalendarRange $range, \DateTimeImmutable $today, ?CalendarMonth $focus = null): CalendarView
    {
        $reservations = $this->reservations->findOverlappingRange($range->start, $range->endExclusive());
        $byUnit = $this->groupByUnit($reservations);

        $conflicts = [];
        $rows = [];
        foreach ($this->units->units() as $unit) {
            $unitReservations = $byUnit[$unit->id] ?? [];
            $conflicting = $this->conflictingIds($unitReservations);
            $conflicts += $conflicting;
            $rows[] = new CalendarRow($unit->name, $this->lanes($range, $unitReservations, $conflicting));
        }

        return new CalendarView(
            $range,
            $this->days($range, $today, $focus),
            $rows,
            $this->serviceRows($range, $reservations, $today),
            \count($conflicts),
        );
    }

    /** Pásek nejbližších dnů od dneška — podklad pro přehled. */
    public function buildNextDays(int $days, \DateTimeImmutable $today): CalendarView
    {
        return $this->build(new CalendarRange($today, $days), $today);
    }

    /** @return list<CalendarDay> */
    private function days(CalendarRange $range, \DateTimeImmutable $today, ?CalendarMonth $focus): array
    {
        $days = [];
        for ($i = 0; $i < $range->days; $i++) {
            $date = $range->start->modify(sprintf('+%d days', $i));
            $weekday = (int) $date->format('w');
            $days[] = new CalendarDay(
                $date,
                $i,
                CzechCalendar::dayShort($weekday),
                $date->format('Y-m-d') === $today->format('Y-m-d'),
                $weekday === 0 || $weekday === 6,
                $focus !== null && !$focus->contains($date),
            );
        }

        return $days;
    }

    /**
     * @param Reservation[] $reservations
     *
     * @return array<string, list<Reservation>>
     */
    private function groupByUnit(array $reservations): array
    {
        $byUnit = [];
        foreach ($reservations as $reservation) {
            $byUnit[$this->units->unitIdFor($reservation)][] = $reservation;
        }

        return $byUnit;
    }

    /**
     * @param list<Reservation> $reservations
     *
     * @return array<int, true> ID rezervací, které se s jinou překrývají
     */
    private function conflictingIds(array $reservations): array
    {
        $ids = [];
        foreach ($this->conflictFinder->find($reservations) as $conflict) {
            $ids[(int) $conflict->a->getId()] = true;
            $ids[(int) $conflict->b->getId()] = true;
        }

        return $ids;
    }

    /**
     * Pásy rozdělené do drah — překrývající se rezervace nesmí skončit v jedné
     * dráze, jinak by se dvojí prodej schoval jeden pod druhý.
     *
     * @param list<Reservation> $reservations
     * @param array<int, true>  $conflicting
     *
     * @return list<list<CalendarBar>>
     */
    private function lanes(CalendarRange $range, array $reservations, array $conflicting): array
    {
        $bars = [];
        foreach ($reservations as $reservation) {
            $bar = $this->bar($range, $reservation, isset($conflicting[(int) $reservation->getId()]));
            if ($bar !== null) {
                $bars[] = $bar;
            }
        }
        usort($bars, static fn (CalendarBar $a, CalendarBar $b): int => $a->span->start <=> $b->span->start);

        $lanes = [];
        foreach ($bars as $bar) {
            $lanes[$this->freeLane($lanes, $bar)][] = $bar;
        }

        return array_values($lanes);
    }

    /**
     * @param array<int, list<CalendarBar>> $lanes
     */
    private function freeLane(array $lanes, CalendarBar $bar): int
    {
        foreach ($lanes as $index => $lane) {
            $last = end($lane);
            if ($last === false || !$last->span->overlaps($bar->span)) {
                return $index;
            }
        }

        return \count($lanes);
    }

    private function bar(CalendarRange $range, Reservation $reservation, bool $conflict): ?CalendarBar
    {
        $span = BarSpan::forStay($range, $reservation->getCheckIn(), self::stayEnd($reservation));
        if ($span === null) {
            return null;
        }

        return new CalendarBar(
            (int) $reservation->getId(),
            $reservation->getGuestName() ?: $reservation->getChannel()->label(),
            $this->summary($reservation),
            $reservation->getChannel(),
            $reservation->getStatus() === ReservationStatus::NEEDS_DETAILS,
            $conflict,
            $span,
        );
    }

    private function summary(Reservation $reservation): string
    {
        $end = self::stayEnd($reservation);

        return sprintf(
            '%s — %s, %s–%s (%s)',
            $reservation->getGuestName() ?: 'Bez jména',
            $reservation->getChannel()->label(),
            $reservation->getCheckIn()->format('j. n.'),
            $end->format('j. n. Y'),
            $reservation->getStatus()->label(),
        );
    }

    /**
     * @param Reservation[] $reservations
     *
     * @return list<CalendarRow>
     */
    private function serviceRows(CalendarRange $range, array $reservations, \DateTimeImmutable $today): array
    {
        return [
            new CalendarRow('Úklid', [], $this->cleaningMarkers($range, $reservations)),
            new CalendarRow('Termíny', [], $this->taskMarkers($range, $today)),
        ];
    }

    /**
     * Úklid se váže na rezervaci, ne na vlastní datum — kreslí se na den odjezdu.
     *
     * @param Reservation[] $reservations
     *
     * @return list<CalendarMarker>
     */
    private function cleaningMarkers(CalendarRange $range, array $reservations): array
    {
        $cleanings = $this->cleanings->findByReservations($reservations);
        $markers = [];
        foreach ($reservations as $reservation) {
            $cleaning = $cleanings[(int) $reservation->getId()] ?? null;
            $checkOut = $reservation->getCheckOut();
            if ($cleaning === null || $checkOut === null || !$range->contains($checkOut)) {
                continue;
            }

            $markers[] = new CalendarMarker(
                $range->offsetOf($checkOut),
                '🧹',
                $cleaning->getType()->label(),
                'secondary',
                $this->urls->generate('reservation_detail', ['id' => $reservation->getId()]),
            );
        }

        return $markers;
    }

    /** @return list<CalendarMarker> */
    private function taskMarkers(CalendarRange $range, \DateTimeImmutable $today): array
    {
        $markers = [];
        foreach ($this->tasks->findDueBetween($range->start, $range->endExclusive()) as $task) {
            $dueOn = $task->getDueOn();
            if ($dueOn === null) {
                continue;
            }

            $urgency = $task->urgencyAt($today);
            $markers[] = new CalendarMarker(
                $range->offsetOf($dueOn),
                $urgency->needsAttention() ? '⚠' : '🔧',
                $task->getName(),
                $urgency->badge(),
                $this->urls->generate('task_show', ['id' => $task->getId()]),
            );
        }

        return $markers;
    }

    /** Pobyt bez odjezdu bereme jako jednu noc — stejně jako kontrola obsazenosti. */
    private static function stayEnd(Reservation $reservation): \DateTimeImmutable
    {
        return $reservation->getCheckOut() ?? $reservation->getCheckIn()->modify('+1 day');
    }
}
