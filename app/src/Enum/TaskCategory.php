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
 * Kategorie hlídaného termínu. Třídí seznam a napovídá, do jaké kategorie
 * výdaje se provedení zaúčtuje, když se cena zapisuje do cashflow.
 */
enum TaskCategory: string
{
    case REVISION = 'revision';
    case SERVICE = 'service';
    case ADMIN = 'admin';
    case PAYMENT = 'payment';
    case SEASONAL = 'seasonal';

    public function label(): string
    {
        return match ($this) {
            self::REVISION => 'Revize a kontroly',
            self::SERVICE => 'Servis a údržba',
            self::ADMIN => 'Administrativa',
            self::PAYMENT => 'Platby a smlouvy',
            self::SEASONAL => 'Sezónní úkony',
        };
    }

    /** Kategorie výdaje nabídnutá při zápisu provedení. */
    public function defaultExpenseCategory(): ExpenseCategory
    {
        return match ($this) {
            self::REVISION, self::SERVICE, self::SEASONAL => ExpenseCategory::MAINTENANCE,
            self::ADMIN => ExpenseCategory::OTHER,
            self::PAYMENT => ExpenseCategory::INSURANCE,
        };
    }
}
