<?php

declare(strict_types=1);

namespace App\Ubyport;

/**
 * Lhůta pro nahlášení ubytovaného cizince do Ubyportu: do 3 pracovních dnů
 * od ubytování (příjezdu) — zákon č. 326/1999 Sb., § 102 odst. 1.
 *
 * Pozn.: počítají se jen víkendy, NE státní svátky (ty by reálnou lhůtu ještě
 * prodloužily) — deadline je tedy mírně konzervativní (dřív, než zákon nutně
 * vyžaduje), což je u připomínky bezpečné.
 */
final class ReportingDeadline
{
    private const WORKING_DAYS = 3;

    /** Poslední den, kdy lze cizince nahlásit (včetně). */
    public function deadlineFor(\DateTimeImmutable $checkIn): \DateTimeImmutable
    {
        $day = $checkIn->setTime(0, 0, 0);
        $added = 0;
        while ($added < self::WORKING_DAYS) {
            $day = $day->modify('+1 day');
            if ((int) $day->format('N') < 6) {
                $added++;
            }
        }

        return $day;
    }

    /**
     * Stav lhůty vůči dnešku: 'overdue' (po termínu), 'due_soon' (dnes/zítra),
     * 'ok' (víc času).
     */
    public function state(\DateTimeImmutable $deadline, \DateTimeImmutable $today): string
    {
        $today = $today->setTime(0, 0, 0);
        if ($today > $deadline) {
            return 'overdue';
        }

        return $this->daysLeft($deadline, $today) <= 1 ? 'due_soon' : 'ok';
    }

    /** Počet dní do termínu (záporné = po termínu). */
    public function daysLeft(\DateTimeImmutable $deadline, \DateTimeImmutable $today): int
    {
        return (int) $today->setTime(0, 0, 0)->diff($deadline)->format('%r%a');
    }
}
