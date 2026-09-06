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
 * Jak byla uzavřená akce doopravdy vyřízena. Zpráva buď odešla naší poštou,
 * nebo ji ubytovatelka vyřídila sama mimo aplikaci — typicky v chatu portálu.
 * U akcí, které se uzavřou samy podle stavu rezervace, zůstává prázdná.
 */
enum ActionDelivery: string
{
    case EMAIL = 'email';
    case MANUAL = 'manual';
}
