<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Credential;

use App\Repository\CredentialRepository;

/**
 * Jediné místo, které ví, odkud se berou přístupové údaje (IMAP, MotoPress).
 * Přednost má hodnota z DB (šifrovaná, zadaná v UI), fallback je env — díky tomu
 * self-host / vývoj funguje z .env a hostovaná instance si creds vyplní v UI.
 * Konzumenti (MotoPressClient, IMAP poller) čtou jen přes tohoto providera.
 */
final class CredentialProvider
{
    /** field => [Credential klíč, je to tajemství?]. Tajemství se v UI nevypisují. */
    public const FIELDS = [
        'imapHost' => ['imap.host', false],
        'imapPort' => ['imap.port', false],
        'imapEncryption' => ['imap.encryption', false],
        'imapUsername' => ['imap.username', false],
        'imapPassword' => ['imap.password', true],
        'imapFolder' => ['imap.folder', false],
        'motopressBaseUrl' => ['motopress.base_url', false],
        'motopressConsumerKey' => ['motopress.consumer_key', true],
        'motopressConsumerSecret' => ['motopress.consumer_secret', true],
    ];

    /** @var array<string, string> env fallback hodnoty podle pole */
    private readonly array $envFallback;

    public function __construct(
        private readonly CredentialRepository $credentials,
        string $imapHost,
        int $imapPort,
        string $imapEncryption,
        string $imapUsername,
        #[\SensitiveParameter]
        string $imapPassword,
        string $imapFolder,
        string $motopressBaseUrl,
        #[\SensitiveParameter]
        string $motopressConsumerKey,
        #[\SensitiveParameter]
        string $motopressConsumerSecret,
    ) {
        $this->envFallback = [
            'imapHost' => $imapHost,
            'imapPort' => (string) $imapPort,
            'imapEncryption' => $imapEncryption,
            'imapUsername' => $imapUsername,
            'imapPassword' => $imapPassword,
            'imapFolder' => $imapFolder,
            'motopressBaseUrl' => $motopressBaseUrl,
            'motopressConsumerKey' => $motopressConsumerKey,
            'motopressConsumerSecret' => $motopressConsumerSecret,
        ];
    }

    public function imapHost(): string
    {
        return $this->get('imapHost');
    }

    public function imapPort(): int
    {
        return (int) $this->get('imapPort');
    }

    public function imapEncryption(): string
    {
        return $this->get('imapEncryption');
    }

    public function imapUsername(): string
    {
        return $this->get('imapUsername');
    }

    public function imapPassword(): string
    {
        return $this->get('imapPassword');
    }

    public function imapFolder(): string
    {
        return $this->get('imapFolder');
    }

    public function motopressBaseUrl(): string
    {
        return $this->get('motopressBaseUrl');
    }

    public function motopressConsumerKey(): string
    {
        return $this->get('motopressConsumerKey');
    }

    public function motopressConsumerSecret(): string
    {
        return $this->get('motopressConsumerSecret');
    }

    /**
     * Hodnoty pro předvyplnění formuláře: necitlivá pole reálně, tajemství jen jako
     * příznak „nastaveno" (plaintext se do UI nikdy nevrací).
     *
     * @return array{values: array<string, string>, secretsSet: array<string, bool>}
     */
    public function formState(): array
    {
        $values = [];
        $secretsSet = [];
        foreach (self::FIELDS as $field => [, $secret]) {
            if ($secret) {
                $secretsSet[$field] = $this->get($field) !== '';
                continue;
            }
            $values[$field] = $this->get($field);
        }

        return ['values' => $values, 'secretsSet' => $secretsSet];
    }

    private function get(string $field): string
    {
        [$key] = self::FIELDS[$field];
        $stored = $this->credentials->getDecrypted($key);

        return $stored !== null && $stored !== '' ? $stored : $this->envFallback[$field];
    }
}
