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
 * Připojení k IMAP schránce z uložených přístupů. Bez argumentu automatizační
 * schránka (poller, test konektoru), jinak zadaná — např. schránka hostů.
 */
final class ImapClientFactory
{
    public function __construct(
        private readonly CredentialProvider $credentials,
    ) {
    }

    /** Připojený IMAP klient. Vyhodí výjimku, když se přihlášení nepovede. */
    public function connect(?ImapAccount $account = null): Client
    {
        $account ??= $this->credentials->automationMailbox();
        $client = (new ClientManager())->make([
            'host' => $account->host,
            'port' => $account->port,
            'encryption' => $account->encryption,
            'validate_cert' => true,
            'username' => $account->username,
            'password' => $account->password,
            'protocol' => 'imap',
        ]);
        $client->connect();

        return $client;
    }
}
