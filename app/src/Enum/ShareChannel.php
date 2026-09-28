<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Enum;

/** Kudy majitel poslal hostovi zprávu mimo poštu aplikace. */
enum ShareChannel: string
{
    case WHATSAPP = 'whatsapp';
    case SMS = 'sms';
    /** Text pro chat portálu — zkopíruje se, odeslání appka nevidí. */
    case COPY = 'copy';

    public function label(): string
    {
        return match ($this) {
            self::WHATSAPP => 'WhatsApp',
            self::SMS => 'SMS',
            self::COPY => 'chat',
        };
    }

    /** Záznam zprávy na časové ose — „WhatsApp: Uvítání". */
    public function noteLabel(): string
    {
        return match ($this) {
            self::WHATSAPP => 'WhatsApp',
            self::SMS => 'SMS',
            self::COPY => 'Zkopírováno do chatu',
        };
    }

    /** Výsledek akce na časové ose po odeslání tímhle kanálem. */
    public function sentResult(): string
    {
        return match ($this) {
            self::WHATSAPP => 'Odesláno přes WhatsApp.',
            self::SMS => 'Odesláno SMS.',
            self::COPY => 'Text zkopírován do chatu.',
        };
    }
}
