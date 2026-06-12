<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Reservation;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * Generuje veřejný check-in token při vzniku rezervace. Token slouží jako
 * neuhodnutelná část URL `/checkin/{token}` v pre-arrival e-mailu hostovi.
 * 64 hex znaků = 256 bitů entropie, kolize jsou prakticky vyloučené.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class ReservationCheckinTokenListener
{
    /** @param LifecycleEventArgs<\Doctrine\ORM\EntityManagerInterface> $args */
    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Reservation) {
            return;
        }

        if ($entity->getCheckinToken() === null) {
            $entity->setCheckinToken(bin2hex(random_bytes(32)));
        }
    }
}
