<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ConnectorStatus;
use App\Enum\ConnectorType;
use App\Repository\ConnectorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Zapínatelný zdroj dat (web/MotoPress, Booking, Airbnb, banka) se stavem zdraví:
 * kdy naposledy běžel, kdy naposledy reálně dorazila data a jak dopadl poslední běh.
 * Jeden řádek na typ konektoru.
 */
#[ORM\Entity(repositoryClass: ConnectorRepository::class)]
#[ORM\Table(name: 'connector')]
#[ORM\UniqueConstraint(name: 'uniq_connector_type', columns: ['type'])]
class Connector
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: ConnectorType::class)]
    private ConnectorType $type;

    #[ORM\Column]
    private bool $enabled = true;

    /** Poslední běh pollu/syncu, který se konektoru dotkl (úspěch i chyba). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    /** Kdy naposledy reálně dorazila data (rezervace, platba, výplata). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastActivityAt = null;

    #[ORM\Column(length: 16, enumType: ConnectorStatus::class)]
    private ConnectorStatus $lastStatus = ConnectorStatus::IDLE;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    public function __construct(ConnectorType $type)
    {
        $this->type = $type;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ConnectorType
    {
        return $this->type;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function getLastActivityAt(): ?\DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function getLastStatus(): ConnectorStatus
    {
        return $this->lastStatus;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /** Zaznamená výsledek běhu. Chyba se u úspěchu maže. */
    public function recordRun(ConnectorStatus $status, ?string $error = null): self
    {
        $this->lastRunAt = new \DateTimeImmutable();
        $this->lastStatus = $status;
        $this->lastError = $status === ConnectorStatus::ERROR ? $error : null;

        return $this;
    }

    /** Posune značku poslední aktivity, jen když je novější než dosavadní. */
    public function recordActivity(\DateTimeImmutable $when): self
    {
        if ($this->lastActivityAt === null || $when > $this->lastActivityAt) {
            $this->lastActivityAt = $when;
        }

        return $this;
    }
}
