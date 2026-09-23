<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\EventListener;

use App\Customer\CustomerLinker;
use App\Entity\Customer;
use App\Entity\Reservation;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Každá uložená rezervace projde `CustomerLinker` — ať přišla ze syncu,
 * z e-mailu, z formuláře nebo z check-inu: bez zákazníka ho dostane, se
 * zákazníkem mu předá kontakt, který mu chybí. Jedno místo, které žádný vstup
 * nemůže obejít.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class ReservationCustomerListener
{
    public function __construct(private readonly CustomerLinker $linker)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $reservationMetadata = $em->getClassMetadata(Reservation::class);
        $customerMetadata = $em->getClassMetadata(Customer::class);

        $changed = [...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()];
        foreach ($changed as $entity) {
            if (!$entity instanceof Reservation) {
                continue;
            }

            $customer = $this->linker->link($entity);
            if ($customer === null) {
                continue;
            }

            // Nový zákazník changeset ještě nemá, stávajícímu mohl přibýt kontakt.
            $customer->getId() === null
                ? $uow->computeChangeSet($customerMetadata, $customer)
                : $uow->recomputeSingleEntityChangeSet($customerMetadata, $customer);
            $uow->recomputeSingleEntityChangeSet($reservationMetadata, $entity);
        }
    }
}
