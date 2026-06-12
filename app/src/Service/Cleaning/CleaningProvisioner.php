<?php

declare(strict_types=1);

namespace App\Service\Cleaning;

use App\Entity\Cleaning;
use App\Entity\Reservation;
use App\Enum\CleaningType;
use App\Repository\CleaningRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Zajistí, že každá rezervace má záznam o úklidu. Defaultní typ = OWNER (vlastní úklid)
 * s cenou podle aktuálního počtu hostů. Idempotentní — pokud cleaning existuje,
 * nesahá na něj.
 */
final class CleaningProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CleaningRepository $cleanings,
        private readonly CleaningPriceList $priceList,
    ) {
    }

    public function ensureForReservation(Reservation $reservation): Cleaning
    {
        $existing = $this->cleanings->findForReservation($reservation);
        if ($existing !== null) {
            return $existing;
        }

        $type = CleaningType::OWNER;
        $cost = $this->priceList->costFor($type, $reservation->getGuestsTotal());
        $payout = $this->priceList->payoutFor($type, $cost);

        $cleaning = new Cleaning($reservation, $type, $cost, $payout);
        $this->em->persist($cleaning);

        return $cleaning;
    }
}
