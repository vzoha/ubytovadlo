<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BalanceStatementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uzávěrka účtu — ruční snapshot reálného stavu k datu. Systém k němu dopočítá
 * očekávaný stav a rozdíl (odhalí nezapsané pohyby); rozdíl lze jedním klikem
 * srovnat korekcí (LedgerEntry typu ADJUSTMENT). Očekávaný stav a rozdíl se
 * neukládají — počítá je BalanceStatementReconciler.
 */
#[ORM\Entity(repositoryClass: BalanceStatementRepository::class)]
#[ORM\Table(name: 'balance_statement')]
#[ORM\Index(name: 'idx_statement_account_date', columns: ['account_id', 'statement_date'])]
class BalanceStatement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Account $account;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $statementDate;

    #[ORM\Column(type: Types::INTEGER)]
    private int $actualBalanceCzk;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Account $account, \DateTimeImmutable $statementDate, int $actualBalanceCzk)
    {
        $this->account = $account;
        $this->statementDate = $statementDate;
        $this->actualBalanceCzk = $actualBalanceCzk;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getStatementDate(): \DateTimeImmutable
    {
        return $this->statementDate;
    }

    public function setStatementDate(\DateTimeImmutable $statementDate): self
    {
        $this->statementDate = $statementDate;

        return $this;
    }

    public function getActualBalanceCzk(): int
    {
        return $this->actualBalanceCzk;
    }

    public function setActualBalanceCzk(int $actualBalanceCzk): self
    {
        $this->actualBalanceCzk = $actualBalanceCzk;

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
