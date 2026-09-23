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
use App\Repository\CustomerDistinctPairRepository;
use App\Repository\CustomerRepository;

/**
 * Najde zákazníky, kteří jsou možná jeden host: stejné jméno (Airbnb neposílá
 * kontakt, takže jiná shoda není), nebo stejný e-mail či telefon u jmen, která
 * k sobě sedí (`NameMatch`) — typicky host bez kontaktu, kterému ho doplnil
 * check-in. Automaticky nic neslučuje; dvojice, o kterých ubytovatel řekl, že
 * jsou různí lidé, vynechá.
 */
final class CustomerDuplicateFinder
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CustomerDistinctPairRepository $distinctPairs,
    ) {
    }

    /** @return DuplicateSuggestion[] */
    public function findAll(): array
    {
        return $this->find($this->customers->findWithStays(), $this->distinctPairs->findPairKeys());
    }

    /** @return DuplicateSuggestion[] návrhy, ve kterých je daný zákazník */
    public function findFor(Customer $customer): array
    {
        return array_values(array_filter(
            $this->findAll(),
            static fn (DuplicateSuggestion $s): bool => $s->keep === $customer || $s->merge === $customer,
        ));
    }

    /**
     * @param Customer[]          $customers
     * @param array<string, true> $distinct  klíče "menší-id:větší-id"
     *
     * @return DuplicateSuggestion[]
     */
    public function find(array $customers, array $distinct): array
    {
        usort($customers, static fn (Customer $a, Customer $b): int => (int) $a->getId() <=> (int) $b->getId());

        $suggestions = [];
        foreach (self::groups($customers) as [$reason, $group]) {
            foreach (self::pairs($group) as [$keep, $merge]) {
                $pairKey = $keep->getId() . ':' . $merge->getId();
                if (isset($suggestions[$pairKey]) || isset($distinct[$pairKey])) {
                    continue;
                }
                if ($reason !== DuplicateReason::SAME_NAME && !NameMatch::compatible($keep->getDisplayName(), $merge->getDisplayName())) {
                    continue;
                }
                $suggestions[$pairKey] = new DuplicateSuggestion($keep, $merge, $reason);
            }
        }

        return array_values($suggestions);
    }

    /**
     * Zákazníci seskupení podle shodného jména, e-mailu a telefonu. Pořadí
     * skupin určuje důvod, který se u dvojice ukáže — jméno je nejčitelnější.
     *
     * @param Customer[] $customers
     *
     * @return list<array{DuplicateReason, Customer[]}>
     */
    private static function groups(array $customers): array
    {
        $byName = $byEmail = $byPhone = [];
        foreach ($customers as $customer) {
            $name = NameMatch::key($customer->getDisplayName());
            if ($name !== null) {
                $byName[$name][] = $customer;
            }
            if ($customer->getEmail() !== null) {
                $byEmail[$customer->getEmail()][] = $customer;
            }
            if ($customer->getPhone() !== null) {
                $byPhone[$customer->getPhone()][] = $customer;
            }
        }

        $groups = [];
        foreach ([[DuplicateReason::SAME_NAME, $byName], [DuplicateReason::SAME_EMAIL, $byEmail], [DuplicateReason::SAME_PHONE, $byPhone]] as [$reason, $index]) {
            foreach ($index as $group) {
                if (\count($group) > 1) {
                    $groups[] = [$reason, $group];
                }
            }
        }

        return $groups;
    }

    /**
     * @param Customer[] $group seřazená podle id
     *
     * @return iterable<array{Customer, Customer}>
     */
    private static function pairs(array $group): iterable
    {
        $count = \count($group);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                yield [$group[$i], $group[$j]];
            }
        }
    }
}
