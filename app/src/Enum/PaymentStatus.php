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
 * Odvozený stav platby rezervace hostem — kolik z ceny už dorazilo (zaplacené
 * faktury + ruční platby). Není uložený, počítá se z ceny a přijatých plateb.
 * Dává smysl jen u přímé objednávky/webu v Kč (u OTA platí host platformě).
 */
enum PaymentStatus: string
{
    case UNPAID = 'unpaid';
    case PARTIAL = 'partial';
    case PAID = 'paid';

    /** Odvodí stav ze zaplacené částky vůči ceně (obojí v Kč). */
    public static function fromAmounts(float $total, float $paid): self
    {
        if ($paid >= round($total, 2)) {
            return self::PAID;
        }

        return $paid > 0.0 ? self::PARTIAL : self::UNPAID;
    }

    public function label(): string
    {
        return match ($this) {
            self::UNPAID => 'Nezaplaceno',
            self::PARTIAL => 'Částečně',
            self::PAID => 'Zaplaceno',
        };
    }

    /** Bootstrap barva odznaku. */
    public function badge(): string
    {
        return match ($this) {
            self::UNPAID => 'danger',
            self::PARTIAL => 'warning',
            self::PAID => 'success',
        };
    }
}
