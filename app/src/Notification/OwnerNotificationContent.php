<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Notification;

/**
 * Předmět a tělo (Markdown) jedné notifikace ubytovateli, poskládané z typu
 * a kontextu. Layout do HTML zabalí až sender přes EmailLayoutRenderer.
 */
final readonly class OwnerNotificationContent
{
    public function __construct(
        public string $subject,
        public string $bodyMarkdown,
    ) {
    }
}
