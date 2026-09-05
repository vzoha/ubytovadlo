<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Embeddable\PropertyAddress;
use App\Repository\AccommodationProfileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Singleton — objekt, který se pronajímá. Název a adresa jsou ty, které zná
 * host (zprávy, check-in). Hlášení na Ubyport nese název z registrace
 * u cizinecké policie a k němu buď adresu objektu, nebo vlastní adresu
 * zařízení; IDUB a kód přiděluje policie při registraci ubytovatele.
 */
#[ORM\Entity(repositoryClass: AccommodationProfileRepository::class)]
#[ORM\Table(name: 'accommodation_profile')]
class AccommodationProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 12)]
    private string $idub = '';

    #[ORM\Column(length: 5)]
    private string $kod = '';

    #[ORM\Column(length: 255)]
    private string $nazev = '';

    #[ORM\Column(length: 255)]
    private string $spojeni = '';

    #[ORM\Embedded(class: PropertyAddress::class, columnPrefix: false)]
    private PropertyAddress $address;

    #[ORM\Column(name: 'nazev_hlaseni', length: 255, nullable: true)]
    private ?string $reportingName = null;

    #[ORM\Embedded(class: PropertyAddress::class, columnPrefix: 'reporting_')]
    private PropertyAddress $reportingAddress;

    public function __construct()
    {
        $this->address = PropertyAddress::empty();
        $this->reportingAddress = PropertyAddress::empty();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdub(): string
    {
        return $this->idub;
    }

    public function setIdub(string $idub): self
    {
        $this->idub = $idub;

        return $this;
    }

    public function getKod(): string
    {
        return $this->kod;
    }

    public function setKod(string $kod): self
    {
        // Ubyport očekává kód zařízení uppercase (vzor: VODPO),
        // normalizujeme tady, aby form bez transformeru i přímé volání
        // produkovaly stejný výsledek.
        $this->kod = mb_strtoupper($kod, 'UTF-8');

        return $this;
    }

    public function getNazev(): string
    {
        return $this->nazev;
    }

    public function setNazev(string $nazev): self
    {
        $this->nazev = $nazev;

        return $this;
    }

    public function getSpojeni(): string
    {
        return $this->spojeni;
    }

    public function setSpojeni(string $spojeni): self
    {
        $this->spojeni = $spojeni;

        return $this;
    }

    /** Adresa objektu, jak ji zná host. */
    public function getAddress(): PropertyAddress
    {
        return $this->address;
    }

    public function setAddress(PropertyAddress $address): self
    {
        $this->address = $address;

        return $this;
    }

    /** Název zapsaný u cizinecké policie; dokud chybí, zastoupí ho název pro hosty. */
    public function getReportingName(): ?string
    {
        return $this->reportingName;
    }

    public function setReportingName(?string $reportingName): self
    {
        $reportingName = trim((string) $reportingName);
        $this->reportingName = $reportingName !== '' ? $reportingName : null;

        return $this;
    }

    /** Adresa zapsaná u cizinecké policie; prázdná = shodná s adresou objektu. */
    public function getReportingAddress(): PropertyAddress
    {
        return $this->reportingAddress;
    }

    public function setReportingAddress(PropertyAddress $address): self
    {
        $this->reportingAddress = $address;

        return $this;
    }

    /** Zařízení je u policie vedené na vlastní adrese. */
    public function hasOwnReportingAddress(): bool
    {
        return !$this->reportingAddress->isEmpty();
    }

    /** Zařízení vystupuje v hlášení na adrese objektu. */
    public function usesPropertyAddressInReport(): self
    {
        $this->reportingAddress = PropertyAddress::empty();

        return $this;
    }

    public function nameForReport(): string
    {
        return $this->reportingName ?? $this->nazev;
    }

    public function addressForReport(): PropertyAddress
    {
        return $this->reportingAddress->isEmpty() ? $this->address : $this->reportingAddress;
    }
}
