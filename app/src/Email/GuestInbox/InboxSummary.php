<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email\GuestInbox;

/** Hlavička zprávy ze schránky hostů — co stačí na přehled bez stahování těla. */
final readonly class InboxSummary
{
    public function __construct(
        public string $uid,
        public InboxSender $from,
        public string $subject,
        public \DateTimeImmutable $date,
        public bool $answered,
    ) {
    }
}
