<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Formatting;

/**
 * Datum pro text zprávy hostovi. Části data drží pohromadě nezlomitelná
 * mezera, aby se „19. 9. 2026“ nerozpadlo přes dva řádky e-mailu.
 */
final class GuestDate
{
    public static function format(\DateTimeImmutable $date, string $locale = 'cs'): string
    {
        return $date->format($locale === 'cs' ? "j.\u{00a0}n.\u{00a0}Y" : "j\u{00a0}M\u{00a0}Y");
    }
}
