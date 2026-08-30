<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Calendar;

use App\Formatting\CzechCalendar;

/**
 * Měsíc, který kalendář zrovna ukazuje, i s navigací na sousední. Z URL chodí
 * jako `2026-09`; cokoli jiného spadne na aktuální měsíc, ať se stránka nedá
 * shodit ručně upraveným odkazem.
 */
final readonly class CalendarMonth
{
    public function __construct(
        public int $year,
        public int $month,
    ) {
    }

    public static function fromDate(\DateTimeImmutable $date): self
    {
        return new self((int) $date->format('Y'), (int) $date->format('n'));
    }

    public static function fromString(?string $value, \DateTimeImmutable $fallback): self
    {
        if ($value === null || preg_match('/^(\d{4})-(\d{2})$/', $value, $m) !== 1) {
            return self::fromDate($fallback);
        }

        $month = (int) $m[2];
        $year = (int) $m[1];
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            return self::fromDate($fallback);
        }

        return new self($year, $month);
    }

    public function firstDay(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $this->year, $this->month));
    }

    public function length(): int
    {
        return (int) $this->firstDay()->format('t');
    }

    public function previous(): self
    {
        return self::fromDate($this->firstDay()->modify('-1 month'));
    }

    public function next(): self
    {
        return self::fromDate($this->firstDay()->modify('+1 month'));
    }

    public function value(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function label(): string
    {
        return sprintf('%s %d', CzechCalendar::monthName($this->month), $this->year);
    }

    public function contains(\DateTimeImmutable $date): bool
    {
        return (int) $date->format('Y') === $this->year && (int) $date->format('n') === $this->month;
    }

    /** Osa ukazuje přesně měsíc — od prvního do posledního dne. */
    public function timelineRange(): CalendarRange
    {
        return new CalendarRange($this->firstDay(), $this->length());
    }

    /**
     * Mřížka potřebuje celé týdny, takže rozsah přetéká do sousedních měsíců
     * od pondělí před prvním dnem po neděli za posledním.
     */
    public function gridRange(): CalendarRange
    {
        $first = $this->firstDay();
        $start = $first->modify(sprintf('-%d days', ((int) $first->format('N')) - 1));
        $last = $first->modify('last day of this month');
        $end = $last->modify(sprintf('+%d days', 7 - ((int) $last->format('N'))));

        return new CalendarRange($start, (int) $start->diff($end)->days + 1);
    }
}
