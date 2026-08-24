<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExpenseCategory;
use App\Enum\TaskCategory;
use App\Enum\TaskIntervalUnit;
use App\Enum\TaskRecurrence;
use App\Enum\TaskUrgency;
use App\Repository\RecurringTaskRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Hlídaný opakovaný termín — revize hasicích přístrojů, kontrola komína, odvod
 * poplatku obci, zazimování vody. Jedna entita pro všechno, co se opakuje a má
 * datum; liší se jen kategorií a způsobem výpočtu příštího termínu.
 *
 * `dueOn` je jediný zdroj pravdy o tom, kdy se úkon má udělat. Zápis provedení
 * (`TaskCompletion`) ho posune podle `recurrence` a intervalu — viz TaskScheduler.
 */
#[ORM\Entity(repositoryClass: RecurringTaskRepository::class)]
#[ORM\Table(name: 'recurring_task')]
#[ORM\Index(name: 'idx_task_due', columns: ['active', 'due_on'])]
class RecurringTask
{
    /** Výchozí předstih upozornění ve dnech — měsíc stačí na objednání technika. */
    public const DEFAULT_LEAD_DAYS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 16, enumType: TaskCategory::class)]
    private TaskCategory $category;

    #[ORM\Column(length: 16, enumType: TaskRecurrence::class)]
    private TaskRecurrence $recurrence;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $intervalValue = null;

    #[ORM\Column(length: 8, nullable: true, enumType: TaskIntervalUnit::class)]
    private ?TaskIntervalUnit $intervalUnit = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueOn = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastDoneOn = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $leadDays = self::DEFAULT_LEAD_DAYS;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $vendor = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $vendorContact = null;

    /** Předpis nebo norma, ze které lhůta plyne (`vyhl. 246/2001 Sb.`). */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $legalReference = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $estimatedCostCzk = null;

    #[ORM\Column(length: 24, nullable: true, enumType: ExpenseCategory::class)]
    private ?ExpenseCategory $expenseCategory = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** Klíč šablony z katalogu, ze které termín vznikl. */
    #[ORM\Column(length: 48, nullable: true)]
    private ?string $catalogKey = null;

    /** Termín, na který už upozornění odešlo — drží připomínku idempotentní. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $remindedForDue = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRemindedOn = null;

    /** @var Collection<int, TaskCompletion> */
    #[ORM\OneToMany(mappedBy: 'task', targetEntity: TaskCompletion::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['doneOn' => 'DESC', 'id' => 'DESC'])]
    private Collection $completions;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, TaskCategory $category, TaskRecurrence $recurrence)
    {
        $this->name = $name;
        $this->category = $category;
        $this->recurrence = $recurrence;
        $this->completions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this->touch();
    }

    public function getCategory(): TaskCategory
    {
        return $this->category;
    }

    public function setCategory(TaskCategory $category): self
    {
        $this->category = $category;

        return $this->touch();
    }

    public function getRecurrence(): TaskRecurrence
    {
        return $this->recurrence;
    }

    public function setRecurrence(TaskRecurrence $recurrence): self
    {
        $this->recurrence = $recurrence;

        return $this->touch();
    }

    public function getIntervalValue(): ?int
    {
        return $this->intervalValue;
    }

    public function getIntervalUnit(): ?TaskIntervalUnit
    {
        return $this->intervalUnit;
    }

    public function setInterval(?int $value, ?TaskIntervalUnit $unit): self
    {
        $usable = $value !== null && $value > 0 && $unit !== null;
        $this->intervalValue = $usable ? $value : null;
        $this->intervalUnit = $usable ? $unit : null;

        return $this->touch();
    }

    public function hasInterval(): bool
    {
        return $this->intervalValue !== null && $this->intervalUnit !== null;
    }

    public function getDueOn(): ?\DateTimeImmutable
    {
        return $this->dueOn;
    }

    public function setDueOn(?\DateTimeImmutable $dueOn): self
    {
        $this->dueOn = $dueOn;

        return $this->touch();
    }

    public function getLastDoneOn(): ?\DateTimeImmutable
    {
        return $this->lastDoneOn;
    }

    public function setLastDoneOn(?\DateTimeImmutable $lastDoneOn): self
    {
        $this->lastDoneOn = $lastDoneOn;

        return $this->touch();
    }

    public function getLeadDays(): int
    {
        return $this->leadDays;
    }

    public function setLeadDays(int $leadDays): self
    {
        $this->leadDays = max(0, $leadDays);

        return $this->touch();
    }

    public function getVendor(): ?string
    {
        return $this->vendor;
    }

    public function setVendor(?string $vendor): self
    {
        $this->vendor = $vendor;

        return $this->touch();
    }

    public function getVendorContact(): ?string
    {
        return $this->vendorContact;
    }

    public function setVendorContact(?string $vendorContact): self
    {
        $this->vendorContact = $vendorContact;

        return $this->touch();
    }

    public function getLegalReference(): ?string
    {
        return $this->legalReference;
    }

    public function setLegalReference(?string $legalReference): self
    {
        $this->legalReference = $legalReference;

        return $this->touch();
    }

    public function getEstimatedCostCzk(): ?int
    {
        return $this->estimatedCostCzk;
    }

    public function setEstimatedCostCzk(?int $estimatedCostCzk): self
    {
        $this->estimatedCostCzk = $estimatedCostCzk;

        return $this->touch();
    }

    public function getExpenseCategory(): ?ExpenseCategory
    {
        return $this->expenseCategory;
    }

    public function setExpenseCategory(?ExpenseCategory $expenseCategory): self
    {
        $this->expenseCategory = $expenseCategory;

        return $this->touch();
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this->touch();
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this->touch();
    }

    public function getCatalogKey(): ?string
    {
        return $this->catalogKey;
    }

    public function setCatalogKey(?string $catalogKey): self
    {
        $this->catalogKey = $catalogKey;

        return $this->touch();
    }

    public function getRemindedForDue(): ?\DateTimeImmutable
    {
        return $this->remindedForDue;
    }

    public function getLastRemindedOn(): ?\DateTimeImmutable
    {
        return $this->lastRemindedOn;
    }

    public function markReminded(\DateTimeImmutable $today): self
    {
        $this->remindedForDue = $this->dueOn;
        $this->lastRemindedOn = $today;

        return $this->touch();
    }

    /** @return Collection<int, TaskCompletion> */
    public function getCompletions(): Collection
    {
        return $this->completions;
    }

    public function addCompletion(TaskCompletion $completion): self
    {
        if (!$this->completions->contains($completion)) {
            $this->completions->add($completion);
        }

        return $this;
    }

    public function removeCompletion(TaskCompletion $completion): self
    {
        $this->completions->removeElement($completion);

        return $this;
    }

    /** Stav termínu vůči dnešku — barva odznaku i řazení seznamu. */
    public function urgencyAt(\DateTimeImmutable $today): TaskUrgency
    {
        if (!$this->active) {
            return TaskUrgency::INACTIVE;
        }
        if ($this->dueOn === null) {
            return TaskUrgency::UNSCHEDULED;
        }
        $due = $this->dueOn->setTime(0, 0);
        $midnight = $today->setTime(0, 0);
        if ($due < $midnight) {
            return TaskUrgency::OVERDUE;
        }

        return $due <= $midnight->modify(sprintf('+%d days', $this->leadDays))
            ? TaskUrgency::DUE_SOON
            : TaskUrgency::OK;
    }

    /** Kolik dní zbývá do termínu (záporně = o kolik je po termínu). */
    public function daysUntilDue(\DateTimeImmutable $today): ?int
    {
        if ($this->dueOn === null) {
            return null;
        }

        return (int) $today->setTime(0, 0)->diff($this->dueOn->setTime(0, 0))->format('%r%a');
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
