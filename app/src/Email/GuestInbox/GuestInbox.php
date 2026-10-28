<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email\GuestInbox;

/**
 * Schránka, kam píšou hosté. Jen čte a zprávy nechává ve stavu, v jakém byly —
 * nepřečtená zůstane nepřečtená, ať ji majitel ve své poště nepřehlédne.
 */
interface GuestInbox
{
    public function isConfigured(): bool;

    /** Adresa schránky — odesílatel odpovědí majitele. */
    public function ownAddress(): string;

    /**
     * Hlavičky zpráv ve složce přijatých od `$since`, od nejnovější.
     *
     * @return list<InboxSummary>
     *
     * @throws GuestInboxException když se ke schránce nebo složce nejde dostat
     */
    public function summaries(MailFolder $folder, \DateTimeImmutable $since): array;

    /**
     * @throws GuestInboxException když se ke schránce nebo složce nejde dostat
     */
    public function fetch(MailFolder $folder, string $uid): ?InboxMessage;
}
