<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CheckinStep;
use App\Repository\CheckinEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Jeden krok hosta v online check-inu (otevření, čtení dokladu, uložení hosta…).
 * Detaily nesou jen technické údaje — zařízení, úspěch čtení, názvy polí, která
 * neprošla — nikdy hodnoty z dokladu.
 */
#[ORM\Entity(repositoryClass: CheckinEventRepository::class)]
#[ORM\Table(name: 'checkin_event')]
#[ORM\Index(name: 'idx_checkin_event_reservation', columns: ['reservation_id', 'occurred_at'])]
class CheckinEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Reservation $reservation;

    #[ORM\Column(length: 32, enumType: CheckinStep::class)]
    private CheckinStep $step;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $details;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(Reservation $reservation, CheckinStep $step, array $details = [], ?\DateTimeImmutable $occurredAt = null)
    {
        $this->reservation = $reservation;
        $this->step = $step;
        $this->details = $details;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): Reservation
    {
        return $this->reservation;
    }

    public function getStep(): CheckinStep
    {
        return $this->step;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
