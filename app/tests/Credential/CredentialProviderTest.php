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

    public function testReadsDbValues(): void
    {
        $this->repo->method('getDecrypted')->willReturnMap([
            ['imap.host', 'db.example.com'],
            ['motopress.consumer_key', 'db-key'],
        ]);

        $provider = $this->provider();

        self::assertSame('db.example.com', $provider->imapHost());
        self::assertSame('db-key', $provider->motopressConsumerKey());
    }

    public function testEmptyWhenNotInDb(): void
    {
        $this->repo->method('getDecrypted')->willReturn(null);

        $provider = $this->provider();

        self::assertSame('', $provider->imapHost());
        self::assertSame('', $provider->imapUsername());
        self::assertSame(0, $provider->imapPort());
    }

    public function testFormStateMasksSecretsAndExposesNonSecrets(): void
    {
        $this->repo->method('getDecrypted')->willReturnMap([
            ['imap.host', 'db.example.com'],
            ['imap.password', 'secret'],
            ['motopress.consumer_secret', null],
        ]);

        $state = $this->provider()->formState();

        self::assertArrayNotHasKey('imapPassword', $state['values']);
        self::assertSame('db.example.com', $state['values']['imapHost']);
        self::assertTrue($state['secretsSet']['imapPassword']);
        // Bez hodnoty v DB tajemství není nastaveno.
        self::assertFalse($state['secretsSet']['motopressConsumerSecret']);
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
        return new CredentialProvider($this->repo);
    }
}
