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
use App\Entity\Reservation;
use App\Repository\CustomerRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Přiřadí rezervaci zákazníka podle e-mailu, případně telefonu (`CustomerKey`).
 * Shoda e-mailu má přednost před shodou telefonu a obě platí, jen když k sobě
 * sedí i jména (`NameMatch`). Když se nikdo nenajde, založí nového zákazníka;
 * bez e-mailu i telefonu nechá rezervaci bez zákazníka — samotné jméno ke
 * spojení nestačí.
 *
 * Hledá i mezi zákazníky, kteří čekají na uložení, takže dvě rezervace téhož
 * hosta v jednom flushi (sync, import) skončí u jednoho zákazníka.
 */
final class CustomerLinker
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return Customer|null zákazník, kterého rezervace dostala; null, když se nepřiřazovalo
     */
    public function link(Reservation $reservation): ?Customer
    {
        if ($reservation->getCustomer() !== null) {
            return null;
        }

        $key = CustomerKey::fromContact($reservation->getGuestContact());
        if ($key->isEmpty()) {
            return null;
        }

        $customer = $this->find($key, $reservation->getGuestName());
        $customer?->absorb($key);
        if ($customer === null) {
            $customer = new Customer($reservation->getGuestName(), $key);
            $this->em->persist($customer);
        }
        $reservation->setCustomer($customer);

        return $customer;
    }

    private function find(CustomerKey $key, ?string $guestName): ?Customer
    {
        $pending = array_filter(
            $this->em->getUnitOfWork()->getScheduledEntityInsertions(),
            static fn (object $entity): bool => $entity instanceof Customer,
        );

        $byEmail = $key->email === null ? [] : [
            ...array_filter($pending, static fn (Customer $c): bool => $c->getEmail() === $key->email),
            ...$this->customers->findByEmail($key->email),
        ];
        $byPhone = $key->phone === null ? [] : [
            ...array_filter($pending, static fn (Customer $c): bool => $c->getPhone() === $key->phone),
            ...$this->customers->findByPhone($key->phone),
        ];

        foreach ([...$byEmail, ...$byPhone] as $candidate) {
            if (NameMatch::compatible($candidate->getDisplayName(), $guestName)) {
                return $candidate;
            }
        }

        return null;
    }
}
