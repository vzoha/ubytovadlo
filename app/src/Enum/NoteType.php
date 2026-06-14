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
 * Druh ruční poznámky na časové ose rezervace (CRM aktivita).
 * Evidence plateb záměrně NENÍ typem — ta zůstává na fakturách.
 */
enum NoteType: string
{
    case POZNAMKA = 'poznamka';
    case HOVOR = 'hovor';
    case EMAIL = 'email';
    case ZPRAVA = 'zprava';
    case OSOBNE = 'osobne';

    public function label(): string
    {
        return match ($this) {
            self::POZNAMKA => 'Poznámka',
            self::HOVOR => 'Hovor',
            self::EMAIL => 'E-mail',
            self::ZPRAVA => 'Zpráva',
            self::OSOBNE => 'Osobně',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::POZNAMKA => '📝',
            self::HOVOR => '📞',
            self::EMAIL => '✉️',
            self::ZPRAVA => '💬',
            self::OSOBNE => '🤝',
        };
    }
}
