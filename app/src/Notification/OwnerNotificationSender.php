<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Notification;

use App\Entity\PendingOwnerNotification;
use App\Mail\MailSettingsProvider;
use App\Mail\RenderedMessage;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Odešle notifikaci ubytovateli na nastavenou adresu příjemce. Z adresa je
 * shodná s e-maily hostům (MailSettings), příjemce je z nastavení notifikací.
 * Výjimku transportu propouští volajícímu (cron), aby neoznačil záznam odeslaný.
 */
final class OwnerNotificationSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly OwnerNotificationRenderer $renderer,
        private readonly OwnerNotificationSettingsProvider $settings,
        private readonly MailSettingsProvider $mailSettings,
    ) {
    }

    public function sendSingle(PendingOwnerNotification $notification): void
    {
        $this->dispatch($this->renderer->renderSingle($notification));
    }

    /**
     * @param list<PendingOwnerNotification> $notifications
     */
    public function sendDigest(array $notifications): void
    {
        $this->dispatch($this->renderer->renderDigest($notifications));
    }

    private function dispatch(RenderedMessage $rendered): void
    {
        $recipient = $this->settings->recipient();
        if ($recipient === null) {
            throw new \RuntimeException('Není nastavena adresa příjemce notifikací.');
        }

        $mail = $this->mailSettings->current();
        $email = (new Email())
            ->from(new Address($mail->senderEmail, $mail->senderName))
            ->to($recipient)
            ->subject($rendered->subject)
            ->text($rendered->text)
            ->html($rendered->html);

        $this->mailer->send($email);
    }
}
