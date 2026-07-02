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
 * Kategorie nerezervačního výdaje. `group()` dělí výdaje na provoz ubytování
 * (reálný náklad — vstupuje do zisku v Ekonomice) a osobní/finanční odliv
 * (splátka úvěru, výběr majitele — jen snižuje stav účtu, není náklad ubytování).
 * `value` řetězce zůstávají stabilní kvůli uloženým datům.
 */
enum ExpenseCategory: string
{
    // Provoz ubytování
    case ELECTRICITY = 'electricity';
    case CLEANING = 'cleaning';
    case LAUNDRY = 'laundry';
    case SUPPLIES = 'supplies';
    case MAINTENANCE = 'maintenance';
    case EQUIPMENT = 'equipment';
    case INSURANCE = 'insurance';
    case OTA_FEE = 'ota_fee';
    case RECREATION_FEE = 'recreation_fee';
    case MUNICIPAL = 'municipal';
    case TAX = 'tax';
    case OTHER = 'other';

    // Osobní a finanční odliv
    case LOAN_PAYMENT = 'loan_payment';
    case OWNER_WITHDRAWAL = 'owner_withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::ELECTRICITY => 'Elektřina a energie',
            self::CLEANING => 'Úklid',
            self::LAUNDRY => 'Prádlo a praní',
            self::SUPPLIES => 'Spotřební materiál a drogerie',
            self::MAINTENANCE => 'Opravy a údržba',
            self::EQUIPMENT => 'Vybavení a nábytek',
            self::INSURANCE => 'Pojištění',
            self::OTA_FEE => 'Provize a poplatky OTA',
            self::RECREATION_FEE => 'Rekreační poplatek',
            self::MUNICIPAL => 'Poplatky obci',
            self::TAX => 'Daně a odvody',
            self::OTHER => 'Ostatní provozní',
            self::LOAN_PAYMENT => 'Splátka úvěru / hypotéky',
            self::OWNER_WITHDRAWAL => 'Osobní výběr / výplata majiteli',
        };
    }

    /** Skupina pro seskupení v UI a rozlišení provozních vs. osobních výdajů. */
    public function group(): ExpenseGroup
    {
        return match ($this) {
            self::LOAN_PAYMENT, self::OWNER_WITHDRAWAL => ExpenseGroup::PERSONAL,
            default => ExpenseGroup::OPERATING,
        };
    }

    /** Provozní náklad ubytování (jde do Ekonomiky) vs. finanční/osobní odliv (jen stav účtu). */
    public function isOperating(): bool
    {
        return $this->group() === ExpenseGroup::OPERATING;
    }
}
