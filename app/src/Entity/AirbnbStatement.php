<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AirbnbStatementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ručně nahraný měsíční Airbnb earnings receipt / payout statement.
 * Airbnb nedává souhrnnou fakturu e-mailem jako Booking, hostitel/ka si ji
 * stahuje z Earnings → Reports v appce a sem nahrává jako podklad pro DPH.
 */
#[ORM\Entity(repositoryClass: AirbnbStatementRepository::class)]
#[ORM\Table(name: 'airbnb_statement')]
#[ORM\Index(name: 'idx_period_to', columns: ['period_to'])]
class AirbnbStatement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodTo;

    /** Součet servisního poplatku hostitele za období (v CZK, Airbnb účtuje rovnou v CZK). */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $commissionCzk;

    #[ORM\Column(length: 512)]
    private string $pdfPath;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /** Rezervace, ke které tento receipt patří (Airbnb posílá doklad per rezervaci). */
    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(name: 'reservation_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Reservation $reservation = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $uploadedAt;

    public function __construct(
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
        string $commissionCzk,
        string $pdfPath,
    ) {
        $this->periodFrom = $periodFrom;
        $this->periodTo = $periodTo;
        $this->commissionCzk = $commissionCzk;
        $this->pdfPath = $pdfPath;
        $this->uploadedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPeriodFrom(): \DateTimeImmutable
    {
        return $this->periodFrom;
    }

    public function setPeriodFrom(\DateTimeImmutable $periodFrom): self
    {
        $this->periodFrom = $periodFrom;

        return $this;
    }

    public function getPeriodTo(): \DateTimeImmutable
    {
        return $this->periodTo;
    }

    public function setPeriodTo(\DateTimeImmutable $periodTo): self
    {
        $this->periodTo = $periodTo;

        return $this;
    }

    public function getCommissionCzk(): string
    {
        return $this->commissionCzk;
    }

    public function setCommissionCzk(string $commissionCzk): self
    {
        $this->commissionCzk = $commissionCzk;

        return $this;
    }

    public function getPdfPath(): string
    {
        return $this->pdfPath;
    }

    public function setPdfPath(string $pdfPath): self
    {
        $this->pdfPath = $pdfPath;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
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
}
