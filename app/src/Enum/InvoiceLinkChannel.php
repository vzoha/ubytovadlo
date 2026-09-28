<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Enum;

/** Kudy odkaz na fakturu odešel hostovi. */
enum InvoiceLinkChannel: string
{
    case WHATSAPP = 'whatsapp';
    case SMS = 'sms';
    case COPY = 'copy';

    public function label(): string
    {
        return match ($this) {
            self::WHATSAPP => 'WhatsApp',
            self::SMS => 'SMS',
            self::COPY => 'zkopírováno',
        };
    }
}
