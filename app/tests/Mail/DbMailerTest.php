<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Credential\CredentialProvider;
use App\Mail\DbMailer;
use App\Repository\CredentialRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AllowMockObjectsWithoutExpectations]
final class DbMailerTest extends TestCase
{
    public function testFallsBackToEnvMailerWhenNoDbSmtp(): void
    {
        // DB nemá SMTP → smtpDsn() je null → deleguje se na dekorovaný env mailer.
        $repo = $this->createMock(CredentialRepository::class);
        $repo->method('getDecrypted')->willReturn(null);
        $credentials = new CredentialProvider($repo, 'h', 993, 'ssl', 'u', 'p', 'INBOX', 'https://x', 'k', 's');

        $email = (new Email())->from('a@example.cz')->to('b@example.cz')->subject('x')->text('y');
        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::once())->method('send')->with($email);

        $mailer = new DbMailer($inner, $credentials, new EventDispatcher(), new NullLogger());
        $mailer->send($email);
    }
}
