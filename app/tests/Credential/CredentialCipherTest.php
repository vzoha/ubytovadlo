<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Credential;

use App\Credential\CredentialCipher;
use PHPUnit\Framework\TestCase;

final class CredentialCipherTest extends TestCase
{
    private const KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='; // 32 B base64

    public function testRoundTrip(): void
    {
        $cipher = new CredentialCipher(self::KEY);

        self::assertTrue($cipher->isReady());
        $encrypted = $cipher->encrypt('tajné-heslo');
        self::assertNotSame('tajné-heslo', $encrypted);
        self::assertSame('tajné-heslo', $cipher->decrypt($encrypted));
    }

    public function testEachEncryptionUsesFreshNonce(): void
    {
        $cipher = new CredentialCipher(self::KEY);

        self::assertNotSame($cipher->encrypt('x'), $cipher->encrypt('x'));
    }

    public function testTamperedCiphertextDecryptsToNull(): void
    {
        $cipher = new CredentialCipher(self::KEY);
        $encrypted = $cipher->encrypt('heslo');

        $tampered = base64_encode('garbage' . base64_decode($encrypted, true));

        self::assertNull($cipher->decrypt($tampered));
    }

    public function testWrongKeyDecryptsToNull(): void
    {
        $encrypted = (new CredentialCipher(self::KEY))->encrypt('heslo');
        $other = new CredentialCipher(base64_encode(str_repeat('z', 32)));

        self::assertNull($other->decrypt($encrypted));
    }

    public function testNotReadyWithoutKey(): void
    {
        $cipher = new CredentialCipher('');

        self::assertFalse($cipher->isReady());
        self::assertNull($cipher->decrypt('whatever'));
        $this->expectException(\LogicException::class);
        $cipher->encrypt('heslo');
    }

    public function testNotReadyWithWrongLengthKey(): void
    {
        self::assertFalse((new CredentialCipher(base64_encode('too-short')))->isReady());
    }
}
