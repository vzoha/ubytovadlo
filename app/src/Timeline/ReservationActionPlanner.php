<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Timeline;

use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Enum\ActionType;
use App\Enum\Channel;
use App\Enum\MessageKind;
use App\Enum\ReservationStatus;
use App\Enum\SendMode;
use App\Invoice\DepositConfig;
use App\Mail\MessageScheduleResolver;
use App\Mail\MessageTemplateProvider;
use App\Repository\ReservationActionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Zakládá automatické akce na časovou osu rezervace. Idempotentní — akci daného
 * typu nezaloží podruhé (a tím respektuje i ruční zrušení existující akce).
 * Pouze persistuje, flush nechává na volajícím.
 *
 * Čas i to, zda vůbec zprávu hostovi zařadit, řídí konfigurace šablony (režim
 * odesílání + časování): vypnutá zpráva se na osu nezaloží, časování určuje kdy.
 */
class ReservationActionPlanner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReservationActionRepository $actions,
        private readonly DepositConfig $depositConfig,
        private readonly MessageTemplateProvider $templates,
        private readonly MessageScheduleResolver $schedule,
    ) {
    }

    /**
     * @return int počet nově založených akcí
     */
    public function planFor(Reservation $reservation): int
    {
        if (in_array($reservation->getStatus(), [ReservationStatus::CANCELLED, ReservationStatus::COMPLETED], true)) {
            return 0;
        }

        // Pobyt už dávno skončil — nemá smysl plánovat budoucí akce zpětně.
        $end = $reservation->getCheckOut() ?? $reservation->getCheckIn();
        if ($end < new \DateTimeImmutable('today')) {
            return 0;
        }

        $checkIn = $reservation->getCheckIn();
        $added = 0;

        // Žádost o zálohu — jen u web/přímých rezervací s tokem, který zálohu bere
        // (OTA si platby řeší samy). Časování drží šablona (výchozí: hned po objednávce).
        if (\in_array($reservation->getChannel(), [Channel::WEB, Channel::DIRECT], true)
            && $this->depositConfig->appliesTo($reservation->getBillingMode())) {
            $added += $this->ensureMessage($reservation, ActionType::RESERVATION_REQUEST_MESSAGE);
        }

        $added += $this->ensureMessage($reservation, ActionType::PRE_ARRIVAL_MESSAGE);
        $added += $this->ensureMessage($reservation, ActionType::POST_STAY_MESSAGE);

        // Doplatek + připomínka jen u toku se zálohou; při „bez zálohy" jde web
        // klasika na jednu fakturu, doplatková akce nedává smysl.
        if ($this->depositConfig->appliesTo($reservation->getBillingMode())) {
            $added += $this->ensure($reservation, ActionType::ISSUE_FINAL_INVOICE, $this->at($checkIn, null, '10:00'));
            $added += $this->ensureMessage($reservation, ActionType::BALANCE_REMINDER);
        }

        // Ubyport — jen u cizinců (host z jiné země než ČR), lhůta 3 dny od příjezdu.
        $country = $reservation->getGuestCountry();
        if ($country !== null && $country !== 'CZ') {
            $added += $this->ensure($reservation, ActionType::UBYPORT_EXPORT, $this->at($checkIn, '+1 day', '09:00'));
        }

        return $added;
    }

    /**
     * Založí zprávu hostovi podle konfigurace její šablony: vypnutá (OFF) se
     * nezaloží, jinak se naplánuje na čas spočtený z časování. Chybí-li rezervaci
     * potřebná kotva (např. odjezd), zprávu přeskočí.
     */
    private function ensureMessage(Reservation $reservation, ActionType $type): int
    {
        $kind = MessageKind::fromActionType($type);
        if ($kind === null) {
            return 0;
        }

        $template = $this->templates->for($kind);
        if ($template->getMode() === SendMode::OFF) {
            return 0;
        }

        $when = $this->schedule->resolve($template, $reservation);
        if ($when === null) {
            return 0;
        }

        return $this->ensure($reservation, $type, $when);
    }

    private function ensure(Reservation $reservation, ActionType $type, \DateTimeImmutable $when): int
    {
        if ($this->actions->hasOfType($reservation, $type)) {
            return 0;
        }
        $this->em->persist(new ReservationAction($reservation, $type, $when));

        return 1;
    }

    private function at(\DateTimeImmutable $date, ?string $modify, string $time): \DateTimeImmutable
    {
        $result = $date;
        if ($modify !== null) {
            $result = $result->modify($modify);
        }
        [$h, $m] = array_map('intval', explode(':', $time));

        return $result->setTime($h, $m);
    }
}
