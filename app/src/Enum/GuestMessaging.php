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
 * Kudy vedou zprávy hostům z jednoho prodejního kanálu.
 */
enum GuestMessaging: string
{
    case EMAIL = 'email';
    case CHAT = 'chat';
    case NONE = 'none';

    public function label(): string
    {
        return match ($this) {
            self::EMAIL => 'E-mailem',
            self::CHAT => 'Do chatu',
            self::NONE => 'Neposílat',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::EMAIL => 'Zpráva odejde sama na e-mail hosta.',
            self::CHAT => 'Text připravíme, do chatu portálu ho vložíš ty.',
            self::NONE => 'Zprávy hostům se z tohoto kanálu nezakládají.',
        };
    }
}
