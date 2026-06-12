<?php

declare(strict_types=1);

namespace App\Enum;

enum InvoiceType: string
{
    case DEPOSIT = 'deposit';
    case FINAL = 'final';
    case FULL = 'full';

    public function label(): string
    {
        return match ($this) {
            self::DEPOSIT => 'Zálohová faktura',
            self::FINAL => 'Konečná faktura (s odpočtem zálohy)',
            self::FULL => 'Faktura',
        };
    }
}
