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

    /**
     * Hlavičky zpráv přijatých za posledních `$days` dní, od nejnovější.
     *
     * @return list<InboxSummary>
     *
     * @throws GuestInboxException když se ke schránce nejde připojit
     */
    public function recent(int $days, int $limit): array;

    /**
     * @throws GuestInboxException když se ke schránce nejde připojit
     */
    public function find(string $uid): ?InboxMessage;
}
