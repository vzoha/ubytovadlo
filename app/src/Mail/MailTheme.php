<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

/**
 * Barevné téma e-mailu hostovi. Preset mění jen primární (záhlaví) a akcentní
 * (tlačítka/odkazy) barvu; neutrální barvy pozadí/textu drží layout napevno,
 * ať jsou maily čitelné napříč klienty. Klíč `custom` čte hexy z nastavení.
 */
final readonly class MailTheme
{
    public function __construct(
        public string $key,
        public string $label,
        public string $primary,
        public string $accent,
    ) {
    }
}
