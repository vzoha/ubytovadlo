<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Credential\CredentialProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\RawMessage;

/**
 * Dekoruje výchozí mailer: SMTP se přednostně bere z nastavení aplikace
 * (CredentialProvider → šifrovaně v DB, zadané v UI). Když v DB SMTP není,
 * padá zpět na dekorovaný mailer z prostředí (MAILER_DSN). Díky tomu se
 * provozní instance konfiguruje z UI a self-host/vývoj dál funguje z .env.
 */
#[AsDecorator('mailer.mailer')]
final class DbMailer implements MailerInterface
{
    private ?MailerInterface $dbMailer = null;

    public function __construct(
        #[AutowireDecorated]
        private readonly MailerInterface $inner,
        private readonly CredentialProvider $credentials,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->resolve()->send($message, $envelope);
    }

    private function resolve(): MailerInterface
    {
        $dsn = $this->credentials->smtpDsn();
        if ($dsn === null) {
            return $this->inner;
        }

        return $this->dbMailer ??= new Mailer(Transport::fromDsn($dsn, $this->dispatcher, null, $this->logger));
    }
}
