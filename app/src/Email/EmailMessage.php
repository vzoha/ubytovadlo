<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email;

final class EmailMessage
{
    /**
     * @param list<EmailAttachment> $attachments
     */
    public function __construct(
        public readonly string $messageId,
        public readonly ?string $fromAddress,
        public readonly string $subject,
        public readonly \DateTimeImmutable $date,
        public readonly string $textBody,
        public readonly array $attachments = [],
    ) {
    }
}
