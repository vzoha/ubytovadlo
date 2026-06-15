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
 * Vyrenderovaná zpráva připravená k odeslání nebo náhledu: předmět, HTML tělo
 * (zabalené v master layoutu) a textová alternativa.
 */
final readonly class RenderedMessage
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
    ) {
    }
}
