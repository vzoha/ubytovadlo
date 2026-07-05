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
 * Výsledek posledního běhu konektoru. Uložený stav; odvozené stavy pro
 * zobrazení (vypnuto, nenastaveno, dlouho bez dat) skládá ConnectorHealth.
 */
enum ConnectorStatus: string
{
    /** Zatím neběžel nebo nemá vyplněné přístupy. */
    case IDLE = 'idle';
    /** Poslední běh proběhl v pořádku. */
    case OK = 'ok';
    /** Poslední běh selhal (nedostupný server, chyba přístupu). */
    case ERROR = 'error';
}
