<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExpenseCategory;
use App\Enum\LedgerEntryType;
use App\Repository\LedgerEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ruční pohyb v cashflow: výdaj, převod mezi vlastními účty, nebo korekce
 * z uzávěrky. Dopad na zůstatek:
 * - EXPENSE:    −amountCzk na `account`
 * - TRANSFER:   −amountCzk na `account` a +amountCzk na `counterAccount`
 * - ADJUSTMENT: ±amountCzk na `account` (částka smí být záporná).
 */
#[ORM\Entity(repositoryClass: LedgerEntryRepository::class)]
#[ORM\Table(name: 'ledger_entry')]
#[ORM\Index(name: 'idx_ledger_account_date', columns: ['account_id', 'occurred_on'])]
class LedgerEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: LedgerEntryType::class)]
    private LedgerEntryType $type;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $occurredOn;

    #[ORM\Column(type: Types::INTEGER)]
    private int $amountCzk;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Account $account;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Account $counterAccount = null;

    #[ORM\Column(length: 24, nullable: true, enumType: ExpenseCategory::class)]
    private ?ExpenseCategory $category = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Reservation $reservation = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(LedgerEntryType $type, \DateTimeImmutable $occurredOn, int $amountCzk, Account $account)
    {
        $this->type = $type;
        $this->occurredOn = $occurredOn;
        $this->amountCzk = $amountCzk;
        $this->account = $account;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): LedgerEntryType
    {
        return $this->type;
    }

    public function getOccurredOn(): \DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function setOccurredOn(\DateTimeImmutable $occurredOn): self
    {
        $this->occurredOn = $occurredOn;

        return $this;
    }

    public function getAmountCzk(): int
    {
        return $this->amountCzk;
    }

    public function setAmountCzk(int $amountCzk): self
    {
        $this->amountCzk = $amountCzk;

        return $this;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function setAccount(Account $account): self
    {
        $this->account = $account;

        return $this;
    }

    public function getCounterAccount(): ?Account
    {
        return $this->counterAccount;
    }

    public function setCounterAccount(?Account $counterAccount): self
    {
        $this->counterAccount = $counterAccount;

        return $this;
    }

    public function getCategory(): ?ExpenseCategory
    {
        return $this->category;
    }

    public function setCategory(?ExpenseCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function setReservation(?Reservation $reservation): self
    {
        $this->reservation = $reservation;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
