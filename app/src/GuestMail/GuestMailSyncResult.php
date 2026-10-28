<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\GuestMail;

/** Co jeden běh synchronizace korespondence stáhl. */
final class GuestMailSyncResult
{
    public int $inbound = 0;

    public int $outbound = 0;

    /** @var list<string> */
    public array $warnings = [];
}
