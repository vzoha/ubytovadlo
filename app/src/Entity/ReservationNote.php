<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NoteType;
use App\Repository\ReservationNoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ruční záznam (CRM aktivita) na časové ose rezervace — minulost, neměnný.
 * Systémové události (založeno, faktura, check-in…) se NEukládají, odvozuje je
 * ReservationTimelineBuilder z existujících polí.
 */
#[ORM\Entity(repositoryClass: ReservationNoteRepository::class)]
#[ORM\Table(name: 'reservation_note')]
#[ORM\Index(name: 'idx_note_reservation', columns: ['reservation_id'])]
class ReservationNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Reservation $reservation;

    #[ORM\Column(length: 16, enumType: NoteType::class)]
    private NoteType $type;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    /** Kdo zápis vytvořil (null = systém). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /** Kdy se to stalo (lze zadat zpětně, default = teď). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Reservation $reservation, NoteType $type, string $body)
    {
        $this->reservation = $reservation;
        $this->type = $type;
        $this->body = $body;
        $this->occurredAt = new \DateTimeImmutable();
        $this->createdAt = $this->occurredAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): Reservation
    {
        return $this->reservation;
    }

    public function getType(): NoteType
    {
        return $this->type;
    }

    public function setType(NoteType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): self
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
