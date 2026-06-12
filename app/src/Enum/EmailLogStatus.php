<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Enum;

enum EmailLogStatus: string
{
    case PENDING = 'pending';
    case PROCESSED = 'processed';
    case IGNORED = 'ignored';
    case ERROR = 'error';
}
