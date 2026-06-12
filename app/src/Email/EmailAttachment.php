<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email;

final class EmailAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $contentType,
        public readonly string $content,
    ) {
    }
}
