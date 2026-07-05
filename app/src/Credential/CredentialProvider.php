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
 * Přístupové údaje (IMAP, MotoPress, SMTP) čte z DB (šifrovaně, zadané v UI).
 * Nenastavené pole vrací prázdno — pollery/sync se pak tiše přeskočí.
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
        'smtpHost' => ['smtp.host', false],
        'smtpPort' => ['smtp.port', false],
        'smtpEncryption' => ['smtp.encryption', false],
        'smtpUsername' => ['smtp.username', false],
        'smtpPassword' => ['smtp.password', true],
    ];

    public function __construct(
        private readonly CredentialRepository $credentials,
    ) {
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

    /** Automatizační schránka je nakonfigurovaná, když má aspoň server a uživatele. */
    public function imapConfigured(): bool
    {
        return $this->imapHost() !== '' && $this->imapUsername() !== '';
    }

    public function motopressBaseUrl(): string
    {
        return $this->get('motopressBaseUrl');
    }

    /** Konektor je nakonfigurovaný, když má adresu webu i oba API klíče. */
    public function motopressConfigured(): bool
    {
        return $this->motopressBaseUrl() !== ''
            && $this->motopressConsumerKey() !== ''
            && $this->motopressConsumerSecret() !== '';
    }

    public function motopressConsumerKey(): string
    {
        return $this->get('motopressConsumerKey');
    }

    public function motopressConsumerSecret(): string
    {
        return $this->get('motopressConsumerSecret');
    }

    public function smtpHost(): string
    {
        return $this->get('smtpHost');
    }

    public function smtpPort(): int
    {
        return (int) $this->get('smtpPort');
    }

    public function smtpEncryption(): string
    {
        return $this->get('smtpEncryption');
    }

    public function smtpUsername(): string
    {
        return $this->get('smtpUsername');
    }

    public function smtpPassword(): string
    {
        return $this->get('smtpPassword');
    }

    /**
     * Symfony Mailer DSN z uložených SMTP údajů, nebo null když není nastaven host
     * (pak se použije MAILER_DSN z prostředí). Šifrování volí schéma: ssl → smtps
     * (implicitní TLS), tls → smtp se STARTTLS, žádné → smtp. Chybějící port se
     * dopočítá z šifrování (465/587/25).
     */
    public function smtpDsn(): ?string
    {
        $host = trim($this->get('smtpHost'));
        if ($host === '') {
            return null;
        }

        $encryption = trim($this->get('smtpEncryption'));
        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';
        $port = $this->smtpPort();
        if ($port === 0) {
            $port = match ($encryption) {
                'ssl' => 465,
                'tls' => 587,
                default => 25,
            };
        }

        $auth = '';
        $user = trim($this->get('smtpUsername'));
        if ($user !== '') {
            $auth = rawurlencode($user) . ':' . rawurlencode($this->get('smtpPassword')) . '@';
        }

        return sprintf('%s://%s%s:%d', $scheme, $auth, $host, $port);
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

        return (string) $this->credentials->getDecrypted($key);
    }
}
