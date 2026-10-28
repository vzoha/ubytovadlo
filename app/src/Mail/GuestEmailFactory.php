<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Hlavička e-mailu hostovi podle nastavení pošty: odesílatel, Reply-To a vlastní
 * Message-ID — podle něj se odpověď hosta spáruje s rezervací.
 */
class GuestEmailFactory
{
    public function __construct(
        private readonly MailSettingsProvider $mailSettings,
    ) {
    }

    public function create(string $to, string $subject, ?string $messageId = null): Email
    {
        $settings = $this->mailSettings->current();

        $email = (new Email())
            ->from(new Address($settings->senderEmail, $settings->senderName))
            ->to($to)
            ->subject($subject);
        if ($settings->replyTo !== null) {
            $email->replyTo($settings->replyTo);
        }
        if ($messageId !== null) {
            $email->getHeaders()->addIdHeader('Message-ID', $messageId);
        }

        return $email;
    }

    public function newMessageId(): string
    {
        $domain = substr(strrchr($this->mailSettings->current()->senderEmail, '@') ?: '@ubytovadlo.local', 1);

        return bin2hex(random_bytes(16)) . '@' . $domain;
    }
}
