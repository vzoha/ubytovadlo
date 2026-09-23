<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Customer\CustomerKey;
use App\Repository\CustomerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Zákazník — ten, kdo rezervuje, napříč všemi svými pobyty. Údaje na rezervaci
 * zůstávají snímkem ke dni pobytu; tady je jen to, podle čeho hosta poznáme
 * příště. E-mail se drží malými písmeny, telefon v E.164 — ve tvaru, ve kterém
 * se podle nich páruje (`CustomerKey`).
 */
#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customer')]
#[ORM\Index(name: 'idx_customer_email', columns: ['email'])]
#[ORM\Index(name: 'idx_customer_phone', columns: ['phone'])]
class Customer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $displayName;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(?string $displayName, CustomerKey $key)
    {
        $this->displayName = $displayName;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->absorb($key);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    /**
     * Doplní chybějící e-mail nebo telefon z dalšího pobytu. Vyplněný údaj
     * nepřepisuje — o tom, který kontakt platí, rozhoduje ubytovatel.
     *
     * @return bool zda se něco změnilo
     */
    public function absorb(CustomerKey $key): bool
    {
        $changed = false;
        if ($this->email === null && $key->email !== null) {
            $this->email = $key->email;
            $changed = true;
        }
        if ($this->phone === null && $key->phone !== null) {
            $this->phone = $key->phone;
            $changed = true;
        }
        if ($changed) {
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $changed;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
