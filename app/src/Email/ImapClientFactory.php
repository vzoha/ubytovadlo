<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email;

use App\Credential\CredentialProvider;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

/**
 * Připojení k automatizační schránce z uložených přístupů. Sdílené mezi pollerem
 * (čte zprávy) a testem konektoru (jen ověří přihlášení).
 */
final class ImapClientFactory
{
    public function __construct(
        private readonly CredentialProvider $credentials,
    ) {
    }

    /** Připojený IMAP klient. Vyhodí výjimku, když se přihlášení nepovede. */
    public function connect(): Client
    {
        $client = (new ClientManager())->make([
            'host' => $this->credentials->imapHost(),
            'port' => $this->credentials->imapPort(),
            'encryption' => $this->credentials->imapEncryption(),
            'validate_cert' => true,
            'username' => $this->credentials->imapUsername(),
            'password' => $this->credentials->imapPassword(),
            'protocol' => 'imap',
        ]);
        $client->connect();

        return $client;
    }
}
