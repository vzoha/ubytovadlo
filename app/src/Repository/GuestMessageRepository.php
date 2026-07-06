<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuestMessage;
use App\Entity\Reservation;
use App\Enum\GuestMessageStatus;
use App\Enum\MessageKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuestMessage>
 */
class GuestMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestMessage::class);
    }

    /** Byla už rezervaci úspěšně odeslána zpráva daného druhu? (pojistka proti duplicitě) */
    public function hasSent(Reservation $reservation, MessageKind $kind): bool
    {
        return $this->count([
            'reservation' => $reservation,
            'kind' => $kind,
            'status' => GuestMessageStatus::SENT,
        ]) > 0;
    }
}
