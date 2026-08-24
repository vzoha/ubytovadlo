<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaskCompletionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Provedení hlídaného termínu — jeden řádek historie: kdy se úkon udělal, kdo ho
 * udělal, co stál a jaký protokol z něj je. Zápis provedení posouvá `dueOn`
 * mateřské úlohy; `validUntil` drží platnost z protokolu, když se od intervalu liší
 * (revizní zpráva s vlastní platností).
 */
#[ORM\Entity(repositoryClass: TaskCompletionRepository::class)]
#[ORM\Table(name: 'task_completion')]
#[ORM\Index(name: 'idx_completion_task', columns: ['task_id', 'done_on'])]
class TaskCompletion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RecurringTask::class, inversedBy: 'completions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RecurringTask $task;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $doneOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $vendor = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $costCzk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** Relativní cesta k protokolu (`var/tasks/12/2026-03-04-revize.pdf`). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $attachmentPath = null;

    /** Původní název souboru — pod ním se protokol stahuje. */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $attachmentName = null;

    /** Výdaj založený v cashflow, pokud se cena zaúčtovala. */
    #[ORM\ManyToOne(targetEntity: LedgerEntry::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LedgerEntry $ledgerEntry = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(RecurringTask $task, \DateTimeImmutable $doneOn)
    {
        $this->task = $task;
        $this->doneOn = $doneOn;
        $this->createdAt = new \DateTimeImmutable();
        $task->addCompletion($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTask(): RecurringTask
    {
        return $this->task;
    }

    public function getDoneOn(): \DateTimeImmutable
    {
        return $this->doneOn;
    }

    public function setDoneOn(\DateTimeImmutable $doneOn): self
    {
        $this->doneOn = $doneOn;

        return $this;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function setValidUntil(?\DateTimeImmutable $validUntil): self
    {
        $this->validUntil = $validUntil;

        return $this;
    }

    public function getVendor(): ?string
    {
        return $this->vendor;
    }

    public function setVendor(?string $vendor): self
    {
        $this->vendor = $vendor;

        return $this;
    }

    public function getCostCzk(): ?int
    {
        return $this->costCzk;
    }

    public function setCostCzk(?int $costCzk): self
    {
        $this->costCzk = $costCzk;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    public function getAttachmentPath(): ?string
    {
        return $this->attachmentPath;
    }

    public function getAttachmentName(): ?string
    {
        return $this->attachmentName;
    }

    public function setAttachment(?string $path, ?string $name): self
    {
        $this->attachmentPath = $path;
        $this->attachmentName = $name;

        return $this;
    }

    public function hasAttachment(): bool
    {
        return $this->attachmentPath !== null;
    }

    public function getLedgerEntry(): ?LedgerEntry
    {
        return $this->ledgerEntry;
    }

    public function setLedgerEntry(?LedgerEntry $ledgerEntry): self
    {
        $this->ledgerEntry = $ledgerEntry;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
