<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email\GuestInbox;

/** Hlavička zprávy ze schránky hostů — co stačí na rozhodnutí, jestli zprávu stahovat celou. */
final readonly class InboxSummary
{
    public function __construct(
        public string $uid,
        public string $messageId,
        public MailAddress $from,
        public MailAddress $to,
        public string $subject,
        public \DateTimeImmutable $date,
        public bool $answered,
        /** Rozesílka nebo automatická odpověď (List-Unsubscribe, Auto-Submitted, Precedence: bulk). */
        public bool $automated,
    ) {
    }
}
