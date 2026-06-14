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
 * Původ PDF faktury. GENERATED = vystavila appka (lze bezpečně přegenerovat).
 * EXTERNAL = naimportované / ručně nahrané PDF (migrace ze starého systému) —
 * appka ho neumí reprodukovat, regenerace ho proto NEpřepisuje.
 */
enum PdfSource: string
{
    case GENERATED = 'generated';
    case EXTERNAL = 'external';

    public function label(): string
    {
        return match ($this) {
            self::GENERATED => 'Vygenerováno aplikací',
            self::EXTERNAL => 'Externí / importované',
        };
    }
}
