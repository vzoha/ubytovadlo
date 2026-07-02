<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\IncomeSource;
use App\Repository\ReservationIncomeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Reálně přijatý příjem rezervace (jeden řádek na rezervaci). Upsertuje se dle
 * priority zdroje (IncomeSource): bankovní kredit > Airbnb výplata > zaplacená
 * faktura > odhad. `manuallyOverridden` zamkne ruční editaci proti auto-přepisu.
 * Airbnb částka je čistá výplata (net po provizi) = to, co reálně přistane na účtu.
 */
#[ORM\Entity(repositoryClass: ReservationIncomeRepository::class)]
#[ORM\Table(name: 'reservation_income')]
#[ORM\UniqueConstraint(name: 'uniq_income_reservation', columns: ['reservation_id'])]
class ReservationIncome
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Reservation $reservation;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $amountCzk;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Account $account = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $receivedOn = null;

    #[ORM\Column(length: 16, enumType: IncomeSource::class)]
    private IncomeSource $source;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $manuallyOverridden = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Reservation $reservation, string $amountCzk, IncomeSource $source)
    {
        $this->reservation = $reservation;
        $this->amountCzk = $amountCzk;
        $this->source = $source;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): Reservation
    {
        return $this->reservation;
    }

    public function getAmountCzk(): string
    {
        return $this->amountCzk;
    }

    public function setAmountCzk(string $amountCzk): self
    {
        $this->amountCzk = $amountCzk;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): self
    {
        $this->account = $account;

        return $this;
    }

    public function getReceivedOn(): ?\DateTimeImmutable
    {
        return $this->receivedOn;
    }

    public function setReceivedOn(?\DateTimeImmutable $receivedOn): self
    {
        $this->receivedOn = $receivedOn;

        return $this;
    }

    public function getSource(): IncomeSource
    {
        return $this->source;
    }

    public function setSource(IncomeSource $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function isManuallyOverridden(): bool
    {
        return $this->manuallyOverridden;
    }

    public function setManuallyOverridden(bool $manuallyOverridden): self
    {
        $this->manuallyOverridden = $manuallyOverridden;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
