<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Credential;

use App\Credential\CredentialProvider;
use App\Repository\CredentialRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class CredentialProviderTest extends TestCase
{
    private CredentialRepository&MockObject $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(CredentialRepository::class);
    }

    public function testPrefersDbValueOverEnvFallback(): void
    {
        $this->repo->method('getDecrypted')->willReturnMap([
            ['imap.host', 'db.example.com'],
            ['motopress.consumer_key', 'db-key'],
        ]);

        $provider = $this->provider();

        self::assertSame('db.example.com', $provider->imapHost());
        self::assertSame('db-key', $provider->motopressConsumerKey());
    }

    public function testFallsBackToEnvWhenDbNullOrEmpty(): void
    {
        $this->repo->method('getDecrypted')->willReturnMap([
            ['imap.host', null],
            ['imap.username', ''],
        ]);

        $provider = $this->provider();

        self::assertSame('env-host', $provider->imapHost());
        self::assertSame('env-user', $provider->imapUsername());
        self::assertSame(993, $provider->imapPort());
    }

    public function testFormStateMasksSecretsAndExposesNonSecrets(): void
    {
        $this->repo->method('getDecrypted')->willReturnMap([
            ['imap.password', 'secret'],
            ['motopress.consumer_secret', null],
        ]);

        $state = $this->provider()->formState();

        self::assertArrayNotHasKey('imapPassword', $state['values']);
        self::assertSame('env-host', $state['values']['imapHost']);
        self::assertTrue($state['secretsSet']['imapPassword']);
        // env fallback je neprázdný, takže se bere jako "nastaveno"
        self::assertTrue($state['secretsSet']['motopressConsumerSecret']);
    }

    public function testSmtpDsnFromStoredCredentials(): void
    {
        $this->repo->method('getDecrypted')->willReturnMap([
            ['smtp.host', 'mail.example.com'],
            ['smtp.port', '465'],
            ['smtp.encryption', 'ssl'],
            ['smtp.username', 'me@example.com'],
            ['smtp.password', 'p@ss:word'],
        ]);

        // ssl → schéma smtps, uživatel i heslo URL-enkódované
        self::assertSame(
            'smtps://me%40example.com:p%40ss%3Aword@mail.example.com:465',
            $this->provider()->smtpDsn(),
        );
    }

    public function testSmtpDsnDefaultsPortAndSchemeFromEncryption(): void
    {
        $this->repo->method('getDecrypted')->willReturnMap([
            ['smtp.host', 'mail.example.com'],
            ['smtp.encryption', 'tls'],
        ]);

        // tls → smtp (STARTTLS) na 587, bez uživatele = bez auth
        self::assertSame('smtp://mail.example.com:587', $this->provider()->smtpDsn());
    }

    public function testSmtpDsnNullWhenHostMissing(): void
    {
        $this->repo->method('getDecrypted')->willReturn(null);

        self::assertNull($this->provider()->smtpDsn());
    }

    private function provider(): CredentialProvider
    {
        return new CredentialProvider(
            $this->repo,
            'env-host',
            993,
            'ssl',
            'env-user',
            'env-pass',
            'INBOX',
            'https://env.example.com',
            'env-ckey',
            'env-csecret',
        );
    }
}
