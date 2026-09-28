<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Enum;

/** Kudy majitel poslal hostovi zprávu mimo poštu aplikace (WhatsApp, SMS, chat, sdílení z telefonu). */
enum ShareChannel: string
{
    case WHATSAPP = 'whatsapp';
    case SMS = 'sms';
    case COPY = 'copy';
    case FILE = 'file';

    public function label(): string
    {
        return match ($this) {
            self::WHATSAPP => 'WhatsApp',
            self::SMS => 'SMS',
            self::COPY => 'zkopírováno',
            self::FILE => 'PDF z telefonu',
        };
    }

    /** Výsledek akce na časové ose po odeslání tímhle kanálem. */
    public function sentResult(): string
    {
        return match ($this) {
            self::WHATSAPP => 'Odesláno přes WhatsApp.',
            self::SMS => 'Odesláno SMS.',
            self::COPY => 'Text zkopírován do chatu.',
            self::FILE => 'PDF sdíleno z telefonu.',
        };
    }
}
