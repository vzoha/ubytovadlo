<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EmailLogStatus;
use App\Repository\EmailLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailLogRepository::class)]
#[ORM\Table(name: 'email_log')]
#[ORM\UniqueConstraint(name: 'uniq_message_id', columns: ['message_id'])]
#[ORM\Index(name: 'idx_status', columns: ['status'])]
class EmailLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $messageId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromAddress = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $parsedAt = null;

    #[ORM\Column(length: 16, enumType: EmailLogStatus::class)]
    private EmailLogStatus $status = EmailLogStatus::PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Reservation $reservation = null;

    public function __construct(string $messageId, \DateTimeImmutable $receivedAt)
    {
        $this->messageId = $messageId;
        $this->receivedAt = $receivedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getFromAddress(): ?string
    {
        return $this->fromAddress;
    }

    public function setFromAddress(?string $fromAddress): self
    {
        $this->fromAddress = $fromAddress;

        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getParsedAt(): ?\DateTimeImmutable
    {
        return $this->parsedAt;
    }

    public function getStatus(): EmailLogStatus
    {
        return $this->status;
    }

    public function getError(): ?string
    {
        return $this->error;
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

    public function markProcessed(?Reservation $reservation = null): self
    {
        $this->status = EmailLogStatus::PROCESSED;
        $this->parsedAt = new \DateTimeImmutable();
        $this->error = null;
        if ($reservation !== null) {
            $this->reservation = $reservation;
        }

        return $this;
    }

    public function markIgnored(?string $reason = null): self
    {
        $this->status = EmailLogStatus::IGNORED;
        $this->parsedAt = new \DateTimeImmutable();
        $this->error = $reason;

        return $this;
    }

    public function markError(string $error): self
    {
        $this->status = EmailLogStatus::ERROR;
        $this->parsedAt = new \DateTimeImmutable();
        $this->error = $error;

        return $this;
    }
}
