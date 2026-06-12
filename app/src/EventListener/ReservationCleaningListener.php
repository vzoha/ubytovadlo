<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Cleaning;
use App\Entity\Reservation;
use App\Service\Cleaning\CleaningProvisioner;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Pro každou nově vkládanou rezervaci zajistí, že existuje záznam o úklidu.
 * Běží v onFlush, aby se nový Cleaning zapsal ve stejné transakci jako rezervace
 * bez nutnosti nested flush (postPersist + flush je per Doctrine docs unsafe).
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class ReservationCleaningListener
{
    public function __construct(private readonly CleaningProvisioner $provisioner)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $cleaningMetadata = $em->getClassMetadata(Cleaning::class);

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!$entity instanceof Reservation) {
                continue;
            }

            $cleaning = $this->provisioner->ensureForReservation($entity);
            if ($cleaning->getId() === null) {
                $uow->computeChangeSet($cleaningMetadata, $cleaning);
            }
        }
    }
}
