<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity\Embeddable;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Stav nahlášení hosta na Ubyport (UNL = vždy jedna rezervace). Neměnný snímek
 * s přechody:
 *  - `exported()`   = stažen UNL k odeslání,
 *  - `confirmed()`  = nahrána doručenka (nebo ruční potvrzení); dorovná i export,
 *  - `new self()`   = zpět do fronty k nahlášení.
 */
#[ORM\Embeddable]
final class UbyportReport
{
    public function __construct(
        #[ORM\Column(name: 'ubyport_exported_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private readonly ?\DateTimeImmutable $exportedAt = null,
        #[ORM\Column(name: 'ubyport_confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private readonly ?\DateTimeImmutable $confirmedAt = null,
        #[ORM\Column(name: 'ubyport_receipt_filename', length: 255, nullable: true)]
        private readonly ?string $receiptFilename = null,
        #[ORM\Column(name: 'ubyport_receipt_accepted', type: Types::INTEGER, nullable: true)]
        private readonly ?int $receiptAccepted = null,
        #[ORM\Column(name: 'ubyport_receipt_rejected', type: Types::INTEGER, nullable: true)]
        private readonly ?int $receiptRejected = null,
    ) {
    }

    public function getExportedAt(): ?\DateTimeImmutable
    {
        return $this->exportedAt;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function getReceiptFilename(): ?string
    {
        return $this->receiptFilename;
    }

    public function getReceiptAccepted(): ?int
    {
        return $this->receiptAccepted;
    }

    public function getReceiptRejected(): ?int
    {
        return $this->receiptRejected;
    }

    /** Označí stažení UNL k odeslání. */
    public function exported(\DateTimeImmutable $at): self
    {
        return new self($at, $this->confirmedAt, $this->receiptFilename, $this->receiptAccepted, $this->receiptRejected);
    }

    /**
     * Potvrdí nahlášení. S doručenkou (filename + počty), nebo ručně (vše null).
     * Nahraná doručenka implikuje odeslaný UNL → dorovná i export, pokud chybí.
     */
    public function confirmed(\DateTimeImmutable $at, ?string $filename = null, ?int $accepted = null, ?int $rejected = null): self
    {
        return new self($this->exportedAt ?? $at, $at, $filename, $accepted, $rejected);
    }
}
