<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email;

/** Přístup k jedné IMAP schránce (automatizační, nebo ta, kam píšou hosté). */
final readonly class ImapAccount
{
    public function __construct(
        public string $host,
        public int $port,
        public string $encryption,
        public string $username,
        #[\SensitiveParameter]
        public string $password,
        public string $folder,
    ) {
    }

    /** Schránka je nakonfigurovaná, když má aspoň server a uživatele. */
    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->username !== '';
    }

    /** Složka ke čtení; prázdná = INBOX. */
    public function folderOrInbox(): string
    {
        return $this->folder !== '' ? $this->folder : 'INBOX';
    }
}
