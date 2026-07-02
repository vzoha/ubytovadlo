<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccountType;
use App\Repository\AccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Účet v evidenci cashflow (bankovní / hotovost). Univerzální — uživatel si
 * zakládá vlastní. `openingBalanceCzk` k `openingDate` je výchozí zůstatek,
 * od kterého se dopočítává očekávaný stav (příjmy − výdaje − převody ± korekce).
 */
#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'account')]
class Account
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(length: 8, enumType: AccountType::class)]
    private AccountType $type;

    #[ORM\Column(type: Types::INTEGER)]
    private int $openingBalanceCzk = 0;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $openingDate;

    #[ORM\Column(type: Types::INTEGER)]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    public function __construct(string $name, AccountType $type, int $openingBalanceCzk = 0, ?\DateTimeImmutable $openingDate = null)
    {
        $this->name = $name;
        $this->type = $type;
        $this->openingBalanceCzk = $openingBalanceCzk;
        $this->openingDate = $openingDate ?? new \DateTimeImmutable('today');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): AccountType
    {
        return $this->type;
    }

    public function setType(AccountType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getOpeningBalanceCzk(): int
    {
        return $this->openingBalanceCzk;
    }

    public function setOpeningBalanceCzk(int $openingBalanceCzk): self
    {
        $this->openingBalanceCzk = $openingBalanceCzk;

        return $this;
    }

    public function getOpeningDate(): \DateTimeImmutable
    {
        return $this->openingDate;
    }

    public function setOpeningDate(\DateTimeImmutable $openingDate): self
    {
        $this->openingDate = $openingDate;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }
}
