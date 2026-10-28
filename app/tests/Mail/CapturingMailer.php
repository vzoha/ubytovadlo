<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/** Mailer, který zprávu jen podrží — testy čtou, co by odešlo. */
final class CapturingMailer implements MailerInterface
{
    public ?RawMessage $sent = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->sent = $message;
    }
}
