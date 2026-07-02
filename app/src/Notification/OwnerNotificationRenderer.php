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
use App\Entity\Reservation;
use App\Enum\OwnerNotificationType;
use App\Enum\ReservationStatus;
use App\Formatting\CzechCalendar;
use App\Formatting\Money;
use App\Mail\EmailLayoutRenderer;
use App\Mail\RenderedMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Poskládá předmět a tělo notifikace ubytovateli z typu a kontextu (rezervace +
 * payload) a zabalí do master layoutu e-mailu. Text se skládá až tady (při
 * odeslání), takže odkazy na rezervaci už mají platné ID.
 */
final class OwnerNotificationRenderer
{
    public function __construct(
        private readonly EmailLayoutRenderer $layout,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function renderSingle(PendingOwnerNotification $notification): RenderedMessage
    {
        $content = $this->contentFor($notification);

        return new RenderedMessage(
            $content->subject,
            $this->layout->render($content->subject, $content->bodyMarkdown),
            $this->layout->bodyToText($content->bodyMarkdown),
        );
    }

    /**
     * Sloučí víc notifikací do jednoho denního souhrnu.
     *
     * @param list<PendingOwnerNotification> $notifications
     */
    public function renderDigest(array $notifications): RenderedMessage
    {
        $subject = sprintf('Souhrn notifikací (%d)', \count($notifications));

        $sections = [];
        $textParts = [];
        foreach ($notifications as $notification) {
            $content = $this->contentFor($notification);
            $sections[] = '## ' . $content->subject . "\n\n" . $content->bodyMarkdown;
            $textParts[] = $content->subject . "\n" . $this->layout->bodyToText($content->bodyMarkdown);
        }
        $body = implode("\n\n---\n\n", $sections);

        return new RenderedMessage($subject, $this->layout->render($subject, $body), implode("\n\n", $textParts));
    }

    public function contentFor(PendingOwnerNotification $notification): OwnerNotificationContent
    {
        $reservation = $notification->getReservation();
        $payload = $notification->getPayload();

        return match ($notification->getType()) {
            OwnerNotificationType::NEW_RESERVATION => $this->newReservation($reservation),
            OwnerNotificationType::PAYMENT_RECEIVED => $this->paymentReceived($reservation, $payload),
            OwnerNotificationType::CHECKIN_COMPLETED => $this->checkinCompleted($reservation, $payload),
            OwnerNotificationType::GUEST_MESSAGE_FAILED => $this->guestMessageFailed($reservation, $payload),
            OwnerNotificationType::VAT_REMINDER => $this->vatReminder($payload),
            OwnerNotificationType::UBYPORT_DUE => $this->ubyportDue($reservation),
        };
    }

    private function newReservation(?Reservation $reservation): OwnerNotificationContent
    {
        if ($reservation === null) {
            return new OwnerNotificationContent('Nová rezervace', 'Přišla nová rezervace.');
        }

        $needsDetails = $reservation->getStatus() === ReservationStatus::NEEDS_DETAILS;
        $body = sprintf(
            "Přišla nová rezervace přes **%s**.\n\n- Host: %s\n- Příjezd: %s\n%s\n%s",
            $reservation->getChannel()->label(),
            $this->guestName($reservation),
            $reservation->getCheckIn()->format('j. n. Y'),
            $needsDetails ? "\nRezervace čeká na doplnění údajů hosta." : '',
            $this->reservationButton($reservation, 'Otevřít rezervaci'),
        );

        return new OwnerNotificationContent(
            sprintf('Nová rezervace — %s, příjezd %s', $reservation->getChannel()->label(), $reservation->getCheckIn()->format('j. n. Y')),
            $body,
        );
    }

    /** @param array<string, mixed> $payload */
    private function paymentReceived(?Reservation $reservation, array $payload): OwnerNotificationContent
    {
        $amount = $this->money($payload);

        return new OwnerNotificationContent(
            'Přišla platba' . ($amount !== '' ? ' ' . $amount : ''),
            sprintf(
                "K rezervaci %s dorazila a spárovala se platba%s.\n\n%s",
                $this->reservationLabel($reservation),
                $amount !== '' ? ' **' . $amount . '**' : '',
                $this->reservationButton($reservation, 'Otevřít rezervaci'),
            ),
        );
    }

    /** @param array<string, mixed> $payload */
    private function checkinCompleted(?Reservation $reservation, array $payload): OwnerNotificationContent
    {
        $documents = (int) ($payload['documents'] ?? 0);
        $docsNote = $documents > 0
            ? sprintf(' a nahrál %d %s.', $documents, $this->pluralDocuments($documents))
            : '.';

        return new OwnerNotificationContent(
            'Host dokončil check-in — ' . $this->reservationLabel($reservation),
            sprintf(
                "Host %s dokončil online check-in%s\n\n%s",
                $this->guestName($reservation),
                $docsNote,
                $this->reservationButton($reservation, 'Otevřít rezervaci'),
            ),
        );
    }

    /** @param array<string, mixed> $payload */
    private function guestMessageFailed(?Reservation $reservation, array $payload): OwnerNotificationContent
    {
        $kind = trim((string) ($payload['kind'] ?? 'zprávy'));
        $error = trim((string) ($payload['error'] ?? ''));

        return new OwnerNotificationContent(
            'Nepodařilo se odeslat zprávu hostovi — ' . $this->reservationLabel($reservation),
            sprintf(
                "Automatickou zprávu (%s) hostovi %s se nepodařilo odeslat.\n%s\nMůžeš ji poslat ručně z detailu rezervace.\n\n%s",
                $kind,
                $this->guestName($reservation),
                $error !== '' ? "\n> " . $error . "\n" : '',
                $this->reservationButton($reservation, 'Otevřít rezervaci'),
            ),
        );
    }

    /** @param array<string, mixed> $payload */
    private function vatReminder(array $payload): OwnerNotificationContent
    {
        $period = (string) ($payload['period'] ?? '');
        $parts = explode('-', $period);
        $year = (int) ($parts[0] ?? 0);
        $month = (int) ($parts[1] ?? 0);
        $label = $month >= 1 && $month <= 12 ? sprintf('%s %d', CzechCalendar::monthName($month), $year) : $period;

        $body = sprintf(
            "Blíží se termín pro **DPH přiznání za %s** (do 25. dne následujícího měsíce). V tomto měsíci jsi přijala provizi od OTA — z ní se odvádí 21 %% DPH reverse charge.\n\nStáhni si podklady a předej účetní.\n\n%s",
            $label,
            $month > 0
                ? sprintf('[[button:Otevřít DPH přehled|%s]]', $this->url('vat_detail', ['year' => $year, 'month' => sprintf('%02d', $month)]))
                : sprintf('[[button:Otevřít DPH přehled|%s]]', $this->url('vat_list')),
        );

        return new OwnerNotificationContent('Připomínka: DPH přiznání za ' . $label, $body);
    }

    private function ubyportDue(?Reservation $reservation): OwnerNotificationContent
    {
        $arrival = $reservation?->getCheckIn()->format('j. n. Y') ?? '';

        return new OwnerNotificationContent(
            'Cizinec k nahlášení na Ubyport — ' . $this->reservationLabel($reservation),
            sprintf(
                "Host %s (příjezd %s) je cizinec a čeká na nahlášení na Ubyport. Lhůta jsou 3 dny od příjezdu.\n\n%s",
                $this->guestName($reservation),
                $arrival,
                $this->reservationButton($reservation, 'Otevřít rezervaci'),
            ),
        );
    }

    private function reservationLabel(?Reservation $reservation): string
    {
        if ($reservation === null) {
            return 'rezervace';
        }
        $name = trim((string) $reservation->getGuestName());

        return $name !== '' ? $name : '#' . $reservation->getId();
    }

    private function guestName(?Reservation $reservation): string
    {
        $name = trim((string) $reservation?->getGuestName());

        return $name !== '' ? $name : '—';
    }

    private function reservationButton(?Reservation $reservation, string $label): string
    {
        if ($reservation === null || $reservation->getId() === null) {
            return '';
        }

        return sprintf('[[button:%s|%s]]', $label, $this->url('reservation_detail', ['id' => $reservation->getId()]));
    }

    /** @param array<string, mixed> $payload */
    private function money(array $payload): string
    {
        $amount = trim((string) ($payload['amount'] ?? ''));
        if ($amount === '') {
            return '';
        }

        return Money::format($amount, trim((string) ($payload['currency'] ?? 'CZK')));
    }

    private function pluralDocuments(int $count): string
    {
        return match (true) {
            $count === 1 => 'doklad',
            $count >= 2 && $count <= 4 => 'doklady',
            default => 'dokladů',
        };
    }

    /** @param array<string, mixed> $params */
    private function url(string $route, array $params = []): string
    {
        return $this->urls->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
