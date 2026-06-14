<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ActionOrigin;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use App\Repository\ReservationActionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Naplánovaná budoucí akce na časové ose rezervace — měnitelná (odložit/upravit/
 * zrušit/spustit). Automatické zakládá ReservationActionPlanner, ruční přidává
 * majitelka. Cron app:actions:run vyhodnocuje PLANNED akce, kterým nadešel čas.
 */
#[ORM\Entity(repositoryClass: ReservationActionRepository::class)]
#[ORM\Table(name: 'reservation_action')]
#[ORM\Index(name: 'idx_action_reservation', columns: ['reservation_id'])]
#[ORM\Index(name: 'idx_action_due', columns: ['status', 'scheduled_for'])]
class ReservationAction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Reservation $reservation;

    #[ORM\Column(length: 32, enumType: ActionType::class)]
    private ActionType $type;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $scheduledFor;

    #[ORM\Column(length: 16, enumType: ActionStatus::class)]
    private ActionStatus $status = ActionStatus::PLANNED;

    #[ORM\Column(length: 8, enumType: ActionOrigin::class)]
    private ActionOrigin $origin;

    /**
     * Parametry akce — předmět/text zprávy, text připomínky atd.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $executedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $result = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Reservation $reservation,
        ActionType $type,
        \DateTimeImmutable $scheduledFor,
        ActionOrigin $origin = ActionOrigin::AUTO,
    ) {
        $this->reservation = $reservation;
        $this->type = $type;
        $this->scheduledFor = $scheduledFor;
        $this->origin = $origin;
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

    public function getType(): ActionType
    {
        return $this->type;
    }

    public function getScheduledFor(): \DateTimeImmutable
    {
        return $this->scheduledFor;
    }

    public function reschedule(\DateTimeImmutable $scheduledFor): self
    {
        $this->scheduledFor = $scheduledFor;
        $this->touch();

        return $this;
    }

    public function getStatus(): ActionStatus
    {
        return $this->status;
    }

    public function getOrigin(): ActionOrigin
    {
        return $this->origin;
    }

    /** @return array<string, mixed>|null */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /** @param array<string, mixed>|null $payload */
    public function setPayload(?array $payload): self
    {
        $this->payload = $payload;
        $this->touch();

        return $this;
    }

    public function getExecutedAt(): ?\DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Krátký lidský popis (z payloadu „text", jinak label typu). */
    public function getLabel(): string
    {
        $text = is_string($this->payload['text'] ?? null) ? trim($this->payload['text']) : '';

        return $text !== '' ? $text : $this->type->label();
    }

    public function markDone(?string $result = null): self
    {
        $this->status = ActionStatus::DONE;
        $this->executedAt = new \DateTimeImmutable();
        $this->result = $result;
        $this->touch();

        return $this;
    }

    public function markFailed(string $result): self
    {
        $this->status = ActionStatus::FAILED;
        $this->executedAt = new \DateTimeImmutable();
        $this->result = $result;
        $this->touch();

        return $this;
    }

    public function markSkipped(?string $result = null): self
    {
        $this->status = ActionStatus::SKIPPED;
        $this->executedAt = new \DateTimeImmutable();
        $this->result = $result;
        $this->touch();

        return $this;
    }

    public function cancel(): self
    {
        $this->status = ActionStatus::CANCELLED;
        $this->touch();

        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
