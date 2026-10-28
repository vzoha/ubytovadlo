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
 * Krok, který host udělal v online check-inu. Z řady kroků se skládá průběh
 * check-inu na časové ose rezervace.
 */
enum CheckinStep: string
{
    case VISIT = 'visit';
    case HOST_FORM = 'host_form';
    case DOCUMENT_READ = 'document_read';
    case HOST_INVALID = 'host_invalid';
    case HOST_SAVED = 'host_saved';
    case HOST_DELETED = 'host_deleted';
    case BILLING_SAVED = 'billing_saved';
}
