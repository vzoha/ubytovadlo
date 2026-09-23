<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CustomerDistinctPairRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dva zákazníci, o kterých ubytovatel řekl, že jsou různí lidé — návrh na
 * sloučení se pro ně už neukáže. Dvojice je neuspořádaná: menší id je vždy
 * `first`, takže (A, B) a (B, A) je tentýž záznam.
 */
#[ORM\Entity(repositoryClass: CustomerDistinctPairRepository::class)]
#[ORM\Table(name: 'customer_distinct_pair')]
#[ORM\UniqueConstraint(name: 'uniq_customer_distinct_pair', columns: ['first_id', 'second_id'])]
class CustomerDistinctPair
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $first;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $second;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Customer $a, Customer $b)
    {
        [$this->first, $this->second] = self::ordered($a, $b);
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * @return array{Customer, Customer}
     */
    public static function ordered(Customer $a, Customer $b): array
    {
        return (int) $a->getId() <= (int) $b->getId() ? [$a, $b] : [$b, $a];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirst(): Customer
    {
        return $this->first;
    }

    public function getSecond(): Customer
    {
        return $this->second;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
