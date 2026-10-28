<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email\GuestInbox;

/** Celá zpráva ze schránky hostů. */
final readonly class InboxMessage
{
    /**
     * @param list<string> $threadIds       Message-ID zpráv, na které navazuje (In-Reply-To, References)
     * @param list<string> $attachmentNames
     */
    public function __construct(
        public InboxSummary $summary,
        public string $text,
        public array $threadIds = [],
        public array $attachmentNames = [],
    ) {
    }
}
