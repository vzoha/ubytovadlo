<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DocumentType;
use App\Repository\GuestDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Údaje hosta pro Ubyport. Vzniká buď ručním zadáním v admin UI,
 * nebo přes online check-in (veřejný link s tokenem na rezervaci).
 * Do Ubyport UNL exportu jdou jen záznamy s is_czech_citizen=false
 * a confirmed_by_guest=true.
 */
#[ORM\Entity(repositoryClass: GuestDocumentRepository::class)]
#[ORM\Table(name: 'guest_document')]
#[ORM\Index(name: 'idx_guest_document_confirmed', columns: ['confirmed_at'])]
#[ORM\Index(name: 'idx_guest_document_reported', columns: ['ubyport_reported_at'])]
class GuestDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(name: 'reservation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Reservation $reservation;

    #[ORM\Column(length: 64)]
    private string $lastName;

    #[ORM\Column(length: 64)]
    private string $firstName;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $birthDate;

    /** ISO 3166-1 alpha-3 z číselníku Nationality (SVK, DEU, …). NULL u Čechů. */
    #[ORM\Column(length: 3, nullable: true)]
    private ?string $nationalityCode = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isCzechCitizen = false;

    #[ORM\Column(length: 32, enumType: DocumentType::class, nullable: true)]
    private ?DocumentType $documentType = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $documentNumber = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $visaNumber = null;

    /** Adresa trvalého bydliště hosta — volný text (Čech v ČR, cizinec v zahraničí), do Ubyport jeden řádek. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $residenceAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** Potvrzení hostem ve veřejném check-in formuláři. NULL = nepotvrzeno. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $ubyportReportedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Reservation $reservation, string $firstName, string $lastName, \DateTimeImmutable $birthDate)
    {
        $this->reservation = $reservation;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->birthDate = $birthDate;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): Reservation
    {
        return $this->reservation;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;
        $this->touch();

        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;
        $this->touch();

        return $this;
    }

    public function getBirthDate(): \DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(\DateTimeImmutable $birthDate): self
    {
        $this->birthDate = $birthDate;
        $this->touch();

        return $this;
    }

    public function getNationalityCode(): ?string
    {
        return $this->nationalityCode;
    }

    public function setNationalityCode(?string $nationalityCode): self
    {
        $this->nationalityCode = $nationalityCode;
        $this->touch();

        return $this;
    }

    public function isCzechCitizen(): bool
    {
        return $this->isCzechCitizen;
    }

    public function setIsCzechCitizen(bool $isCzechCitizen): self
    {
        $this->isCzechCitizen = $isCzechCitizen;
        $this->touch();

        return $this;
    }

    /**
     * Vynuluje všechna cizinecká i dokladová pole — pro režim, kdy české hosty
     * neevidujeme vůbec (host je jen jméno + datum narození).
     */
    public function clearForeignerFields(): self
    {
        $this->nationalityCode = null;
        $this->documentType = null;
        $this->documentNumber = null;
        $this->visaNumber = null;
        $this->residenceAddress = null;
        $this->touch();

        return $this;
    }

    /**
     * Vynuluje jen pole, která patří výhradně do Ubyportu (občanství, vízum) —
     * pro českého hosta v evidenční knize, kde doklad a adresu ponecháváme.
     */
    public function clearUbyportOnlyFields(): self
    {
        $this->nationalityCode = null;
        $this->visaNumber = null;
        $this->touch();

        return $this;
    }

    public function getDocumentType(): ?DocumentType
    {
        return $this->documentType;
    }

    public function setDocumentType(?DocumentType $documentType): self
    {
        $this->documentType = $documentType;
        $this->touch();

        return $this;
    }

    public function getDocumentNumber(): ?string
    {
        return $this->documentNumber;
    }

    public function setDocumentNumber(?string $documentNumber): self
    {
        $this->documentNumber = $documentNumber;
        $this->touch();

        return $this;
    }

    public function getVisaNumber(): ?string
    {
        return $this->visaNumber;
    }

    public function setVisaNumber(?string $visaNumber): self
    {
        $this->visaNumber = $visaNumber;
        $this->touch();

        return $this;
    }

    public function getResidenceAddress(): ?string
    {
        return $this->residenceAddress;
    }

    public function setResidenceAddress(?string $residenceAddress): self
    {
        $this->residenceAddress = $residenceAddress;
        $this->touch();

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;
        $this->touch();

        return $this;
    }

    public function isConfirmedByGuest(): bool
    {
        return $this->confirmedAt !== null;
    }

    public function confirm(): self
    {
        $this->confirmedAt = new \DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function getUbyportReportedAt(): ?\DateTimeImmutable
    {
        return $this->ubyportReportedAt;
    }

    public function markUbyportReported(?\DateTimeImmutable $reportedAt): self
    {
        $this->ubyportReportedAt = $reportedAt;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
