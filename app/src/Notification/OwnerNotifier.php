<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Notification;

use App\Entity\PendingOwnerNotification;
use App\Entity\Reservation;
use App\Enum\OwnerNotificationMode;
use App\Enum\OwnerNotificationType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Vstupní bod pro založení notifikace ubytovateli. Triggery (nová rezervace,
 * platba, selhání zprávy, …) volají jen `notify()`; podle nastaveného režimu se
 * událost zařadí do fronty (`pending_owner_notification`), nebo se zahodí (OFF /
 * bez nastaveného příjemce). Vlastní odeslání řeší cron dispatch/digest.
 *
 * Záznam se jen persistuje (bez flush) — volající kontext (listener transakce /
 * controller / cron) flush zajistí sám.
 */
final class OwnerNotifier
{
    public function __construct(
        private readonly OwnerNotificationSettingsProvider $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Zařadí notifikaci do fronty. Vrací true, pokud se opravdu zařadila (tj. je
     * nastaven příjemce a typ není vypnutý) — volající to využije pro idempotenční
     * guard, ať „upozorněno" neznamená jen „pokus o upozornění".
     *
     * @param array<string, mixed> $payload
     */
    public function notify(OwnerNotificationType $type, ?Reservation $reservation = null, array $payload = []): bool
    {
        if ($this->settings->recipient() === null) {
            return false;
        }

        $mode = $this->settings->modeFor($type);
        if ($mode === OwnerNotificationMode::OFF) {
            return false;
        }

        $this->em->persist(new PendingOwnerNotification($type, $mode, $reservation, $payload));

        return true;
    }
}
