<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GuestMessageStatus;
use App\Enum\MessageKind;
use App\Repository\GuestMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit odeslané (či neúspěšné) zprávy hostovi — stopa „co a komu odešlo" a
 * podklad pro případné ruční znovuodeslání.
 */
#[ORM\Entity(repositoryClass: GuestMessageRepository::class)]
#[ORM\Table(name: 'guest_message')]
#[ORM\Index(name: 'idx_guest_message_reservation_kind', columns: ['reservation_id', 'kind'])]
class GuestMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Reservation $reservation;

    #[ORM\Column(length: 32, enumType: MessageKind::class)]
    private MessageKind $kind;

    #[ORM\Column(length: 255)]
    private string $toEmail;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(length: 16, enumType: GuestMessageStatus::class)]
    private GuestMessageStatus $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Reservation $reservation,
        MessageKind $kind,
        string $toEmail,
        string $subject,
        GuestMessageStatus $status,
        ?string $error = null,
    ) {
        $this->reservation = $reservation;
        $this->kind = $kind;
        $this->toEmail = $toEmail;
        $this->subject = $subject;
        $this->status = $status;
        $this->error = $error;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): Reservation
    {
        return $this->reservation;
    }

    public function getKind(): MessageKind
    {
        return $this->kind;
    }

    public function getToEmail(): string
    {
        return $this->toEmail;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getStatus(): GuestMessageStatus
    {
        return $this->status;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
