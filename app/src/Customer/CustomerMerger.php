<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Customer;

use App\Entity\Customer;
use App\Entity\CustomerDistinctPair;
use App\Entity\Reservation;
use App\Repository\CustomerDistinctPairRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ruční úpravy toho, kdo je kdo: sloučení dvou zákazníků do jednoho, oddělení
 * pobytu do samostatného zákazníka a záznam, že dva zákazníci jsou různí lidé.
 * Rozhoduje vždy ubytovatel — automatické párování dělá `CustomerLinker`.
 */
final class CustomerMerger
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly CustomerDistinctPairRepository $distinctPairs,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Přesune pobyty `$merge` k `$keep`, doplní mu chybějící údaje a `$merge` smaže.
     *
     * @return int počet přesunutých rezervací
     */
    public function merge(Customer $keep, Customer $merge): int
    {
        if ($keep === $merge) {
            return 0;
        }

        $stays = $this->reservations->findAllOfCustomer($merge);
        foreach ($stays as $reservation) {
            $reservation->setCustomer($keep);
        }
        $keep->mergeFrom($merge);
        $this->em->remove($merge);
        $this->em->flush();

        return \count($stays);
    }

    /**
     * Vyjme pobyt ze zákazníka do nového — pro rezervaci, která k hostovi
     * nepatří. Nový zákazník si vezme jméno a kontakt z rezervace a dvojice se
     * zapíše jako různí lidé, ať se hned nenabídne zpátky ke sloučení.
     */
    public function detach(Reservation $reservation): ?Customer
    {
        $previous = $reservation->getCustomer();
        if ($previous === null || \count($this->reservations->findAllOfCustomer($previous)) < 2) {
            return null;
        }

        $customer = new Customer($reservation->getGuestName(), CustomerKey::fromContact($reservation->getGuestContact()));
        $this->em->persist($customer);
        $reservation->setCustomer($customer);
        $this->em->flush();

        $this->markDistinct($previous, $customer);

        return $customer;
    }

    /** Dva zákazníci jsou různí lidé; návrh na sloučení se pro ně už neukáže. */
    public function markDistinct(Customer $a, Customer $b): void
    {
        if ($a === $b || $this->distinctPairs->findPair($a, $b) !== null) {
            return;
        }

        $this->em->persist(new CustomerDistinctPair($a, $b));
        $this->em->flush();
    }
}
