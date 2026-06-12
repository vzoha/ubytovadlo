<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ElectricityTariffRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sazba VT/NT platná od daného data. Distribuční tarif se mění zřídka (~ročně).
 * Hostům elektřinu neúčtujeme (je v ceně), tarif slouží pro provozní statistiku.
 */
#[ORM\Entity(repositoryClass: ElectricityTariffRepository::class)]
#[ORM\Table(name: 'electricity_tariff')]
#[ORM\UniqueConstraint(name: 'uniq_valid_from', columns: ['valid_from'])]
class ElectricityTariff
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $validFrom;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 4)]
    private string $vtRate;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 4)]
    private string $ntRate;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    public function __construct(\DateTimeImmutable $validFrom, string $vtRate, string $ntRate)
    {
        $this->validFrom = $validFrom;
        $this->vtRate = $vtRate;
        $this->ntRate = $ntRate;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getValidFrom(): \DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getVtRate(): string
    {
        return $this->vtRate;
    }

    public function getNtRate(): string
    {
        return $this->ntRate;
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
