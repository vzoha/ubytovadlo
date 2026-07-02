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
 * Kategorie nerezervačního výdaje. `isOperating()` rozhoduje, jestli výdaj
 * vstupuje do provozních nákladů v Ekonomice (provoz/investice objektu), nebo
 * je to jen finanční/osobní odliv peněz (splátka úvěru, výběr majitele), který
 * snižuje stav účtu, ale není provozní náklad ubytování.
 */
enum ExpenseCategory: string
{
    case RECREATION_FEE = 'recreation_fee';
    case OTA_FEE = 'ota_fee';
    case ELECTRICITY = 'electricity';
    case EQUIPMENT = 'equipment';
    case MAINTENANCE = 'maintenance';
    case LAUNDRY = 'laundry';
    case MUNICIPAL = 'municipal';
    case TAX = 'tax';
    case OTHER = 'other';
    case LOAN_PAYMENT = 'loan_payment';
    case OWNER_WITHDRAWAL = 'owner_withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::RECREATION_FEE => 'Rekreační poplatek',
            self::OTA_FEE => 'Provize / poplatky OTA',
            self::ELECTRICITY => 'Elektřina',
            self::EQUIPMENT => 'Vybavení',
            self::MAINTENANCE => 'Opravy a revize',
            self::LAUNDRY => 'Prádlo',
            self::MUNICIPAL => 'Poplatky obci',
            self::TAX => 'Daně a odvody',
            self::OTHER => 'Ostatní',
            self::LOAN_PAYMENT => 'Splátka úvěru / hypotéky',
            self::OWNER_WITHDRAWAL => 'Osobní výběr / výplata majiteli',
        };
    }

    /** Provozní náklad ubytování (jde do Ekonomiky) vs. finanční/osobní odliv (jen stav účtu). */
    public function isOperating(): bool
    {
        return match ($this) {
            self::LOAN_PAYMENT, self::OWNER_WITHDRAWAL => false,
            default => true,
        };
    }
}
