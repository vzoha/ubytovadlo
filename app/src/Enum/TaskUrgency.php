<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Stav hlídaného termínu vůči dnešku a nastavenému předstihu.
 * Určuje barvu odznaku i pořadí v seznamu — nejnaléhavější nahoře.
 */
enum TaskUrgency: string
{
    case OVERDUE = 'overdue';
    case DUE_SOON = 'due_soon';
    case OK = 'ok';
    case UNSCHEDULED = 'unscheduled';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::OVERDUE => 'Po termínu',
            self::DUE_SOON => 'Blíží se',
            self::OK => 'V pořádku',
            self::UNSCHEDULED => 'Bez termínu',
            self::INACTIVE => 'Vypnuto',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::OVERDUE => 'danger',
            self::DUE_SOON => 'warning',
            self::OK => 'success',
            self::UNSCHEDULED, self::INACTIVE => 'secondary',
        };
    }

    /** Čím nižší číslo, tím výš v seznamu. */
    public function rank(): int
    {
        return match ($this) {
            self::OVERDUE => 0,
            self::DUE_SOON => 1,
            self::UNSCHEDULED => 2,
            self::OK => 3,
            self::INACTIVE => 4,
        };
    }

    /** Termín, na který se upozorňuje. */
    public function needsAttention(): bool
    {
        return $this === self::OVERDUE || $this === self::DUE_SOON;
    }
}
