<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VatPeriodRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Měsíční agregace DPH pro identifikovanou osobu. Jedno období = jeden řádek.
 * Slouží jako podklad pro DPH přiznání + úhradu na účet FÚ.
 */
#[ORM\Entity(repositoryClass: VatPeriodRepository::class)]
#[ORM\Table(name: 'vat_period')]
#[ORM\UniqueConstraint(name: 'uniq_year_month', columns: ['year', 'month'])]
class VatPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $year;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $month;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $sumBaseCzk = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $sumVatCzk = '0.00';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $filedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $paidAmountCzk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $year, int $month)
    {
        $this->year = $year;
        $this->month = $month;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function getMonth(): int
    {
        return $this->month;
    }

    public function getSumBaseCzk(): string
    {
        return $this->sumBaseCzk;
    }

    public function setSumBaseCzk(string $sumBaseCzk): self
    {
        $this->sumBaseCzk = $sumBaseCzk;
        $this->touch();

        return $this;
    }

    public function getSumVatCzk(): string
    {
        return $this->sumVatCzk;
    }

    public function setSumVatCzk(string $sumVatCzk): self
    {
        $this->sumVatCzk = $sumVatCzk;
        $this->touch();

        return $this;
    }

    public function getFiledAt(): ?\DateTimeImmutable
    {
        return $this->filedAt;
    }

    public function setFiledAt(?\DateTimeImmutable $filedAt): self
    {
        $this->filedAt = $filedAt;
        $this->touch();

        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): self
    {
        $this->paidAt = $paidAt;
        $this->touch();

        return $this;
    }

    public function getPaidAmountCzk(): ?string
    {
        return $this->paidAmountCzk;
    }

    public function setPaidAmountCzk(?string $paidAmountCzk): self
    {
        $this->paidAmountCzk = $paidAmountCzk;
        $this->touch();

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        $this->touch();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Lhůta pro podání + úhradu DPH přiznání: 25. den následujícího měsíce.
     */
    public function getFilingDueAt(): \DateTimeImmutable
    {
        $nextMonth = $this->month === 12 ? 1 : $this->month + 1;
        $year = $this->month === 12 ? $this->year + 1 : $this->year;

        return new \DateTimeImmutable(sprintf('%04d-%02d-25', $year, $nextMonth));
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
