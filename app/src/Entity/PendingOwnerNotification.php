<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OwnerNotificationMode;
use App\Enum\OwnerNotificationType;
use App\Repository\PendingOwnerNotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fronta notifikací ubytovateli. Trigger jen založí záznam (typ + kontext);
 * doručení řeší cron — okamžité jednotlivě (`app:notifications:dispatch`),
 * denní souhrn hromadně (`app:notifications:digest`). Předmět/tělo se skládá
 * až při odeslání z typu a rezervace (ta už má v ten okamžik ID), takže se tu
 * text neukládá. Režim doručení je snapshot z chvíle vzniku — pozdější změna
 * nastavení neuvázne rozpracované záznamy.
 */
#[ORM\Entity(repositoryClass: PendingOwnerNotificationRepository::class)]
#[ORM\Table(name: 'pending_owner_notification')]
#[ORM\Index(name: 'idx_owner_notif_pending', columns: ['delivery_mode', 'sent_at'])]
#[ORM\Index(name: 'idx_owner_notif_reservation', columns: ['reservation_id'])]
class PendingOwnerNotification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: OwnerNotificationType::class)]
    private OwnerNotificationType $type;

    #[ORM\Column(length: 16, enumType: OwnerNotificationMode::class)]
    private OwnerNotificationMode $deliveryMode;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Reservation $reservation;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        OwnerNotificationType $type,
        OwnerNotificationMode $deliveryMode,
        ?Reservation $reservation = null,
        ?array $payload = null,
    ) {
        $this->type = $type;
        $this->deliveryMode = $deliveryMode;
        $this->reservation = $reservation;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): OwnerNotificationType
    {
        return $this->type;
    }

    public function getDeliveryMode(): OwnerNotificationMode
    {
        return $this->deliveryMode;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload ?? [];
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function markSent(): self
    {
        $this->sentAt = new \DateTimeImmutable();

        return $this;
    }
}
