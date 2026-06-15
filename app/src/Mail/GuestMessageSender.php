<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Entity\GuestMessage;
use App\Entity\MessageTemplate;
use App\Entity\Reservation;
use App\Enum\GuestMessageStatus;
use App\Enum\MessageKind;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Odešle zprávu hostovi e-mailem a zaznamená výsledek do guest_message (audit +
 * pojistka proti duplicitě). Při selhání transportu se zaloguje FAILED a vrátí —
 * volající (executor / UI) podle stavu rozhodne, neshodí celý cron běh.
 */
final class GuestMessageSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly GuestMessageRenderer $renderer,
        private readonly MailSettingsProvider $mailSettings,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
    }

    /** Lze rezervaci poslat e-mail? (má vyplněný e-mail hosta) */
    public function canSend(Reservation $reservation): bool
    {
        return trim((string) $reservation->getGuestEmail()) !== '';
    }

    /**
     * @param array<string, string> $context         dodatečné proměnné (např. invoice_number)
     * @param list<string>          $attachmentPaths absolutní cesty k přílohám (faktura PDF)
     * @param MessageTemplate|null  $override        ad-hoc šablona místo uložené (custom zpráva)
     *
     * @throws \InvalidArgumentException když rezervace nemá e-mail hosta
     */
    public function send(Reservation $reservation, MessageKind $kind, array $context = [], array $attachmentPaths = [], ?MessageTemplate $override = null): GuestMessage
    {
        $to = trim((string) $reservation->getGuestEmail());
        if ($to === '') {
            throw new \InvalidArgumentException('Rezervace nemá e-mail hosta — nelze odeslat zprávu.');
        }

        $useLogo = $this->useLogo();
        $logoSrc = $useLogo ? 'cid:logo' : null;
        $rendered = $override !== null
            ? $this->renderer->renderTemplate($override, $reservation, $context, $logoSrc)
            : $this->renderer->render($kind, $reservation, $context, $logoSrc);

        $status = GuestMessageStatus::SENT;
        $error = null;
        try {
            $this->dispatch($to, $rendered, $useLogo, $attachmentPaths);
        } catch (\Throwable $e) {
            $status = GuestMessageStatus::FAILED;
            $error = $e->getMessage();
            $this->logger->error('Odeslání zprávy hostovi selhalo.', [
                'reservation' => $reservation->getId(),
                'kind' => $kind->value,
                'error' => $error,
            ]);
        }

        $message = new GuestMessage($reservation, $kind, $to, $rendered->subject, $status, $error);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /**
     * Odešle testovací kopii na zadanou adresu (z ukázkové rezervace), bez zápisu
     * do auditu. Výjimky transportu propustí volajícímu (UI zobrazí chybu).
     *
     * @param array<string, string> $context
     */
    public function sendTest(string $to, MessageKind $kind, Reservation $sample, array $context = []): void
    {
        $useLogo = $this->useLogo();
        $rendered = $this->renderer->render($kind, $sample, $context, $useLogo ? 'cid:logo' : null);
        $this->dispatch($to, $rendered, $useLogo, []);
    }

    /**
     * @param list<string> $attachmentPaths
     */
    private function dispatch(string $to, RenderedMessage $rendered, bool $useLogo, array $attachmentPaths): void
    {
        $settings = $this->mailSettings->current();

        $email = (new Email())
            ->from(new Address($settings->senderEmail, $settings->senderName))
            ->to($to)
            ->subject($rendered->subject)
            ->text($rendered->text)
            ->html($rendered->html);

        if ($settings->replyTo !== null) {
            $email->replyTo($settings->replyTo);
        }
        if ($useLogo) {
            $email->embedFromPath($this->logoPath(), 'logo');
        }
        foreach ($attachmentPaths as $path) {
            if (is_file($path)) {
                $email->attachFromPath($path);
            }
        }

        $this->mailer->send($email);
    }

    private function useLogo(): bool
    {
        return $this->mailSettings->current()->showLogo && is_file($this->logoPath());
    }

    private function logoPath(): string
    {
        return $this->projectDir . '/public/assets/logo.png';
    }
}
