<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email\Handler;

use App\Booking\BookingHotelId;
use App\Email\BookingTriggerParser;
use App\Email\Dto\BookingTriggerData;
use App\Email\EmailMessage;
use App\Entity\EmailLog;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\ConnectorType;
use App\Enum\OwnerNotificationType;
use App\Enum\ReservationStatus;
use App\Notification\OwnerNotifier;
use App\Repository\ReservationRepository;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Booking.com trigger e-mail (id + datum příjezdu) → založí `needs_details`
 * rezervaci. Údaje hosta Booking neposílá, doplní je majitelka z extranetu.
 */
final class BookingTriggerHandler implements EmailHandler
{
    public function __construct(
        private readonly BookingTriggerParser $parser,
        private readonly ReservationRepository $reservations,
        private readonly OwnerNotifier $notifier,
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function supports(EmailMessage $email): bool
    {
        return $this->parser->supports($email);
    }

    public function connectorType(): ConnectorType
    {
        return ConnectorType::BOOKING;
    }

    public function handle(EmailMessage $email, EmailLog $log): void
    {
        $data = $this->parser->parse($email);
        $this->rememberHotelId($data->hotelId);
        $log->markProcessed($this->upsert($data));
    }

    /**
     * ID ubytování z odkazu v e-mailu — drží ho odkaz z rezervace do extranetu.
     * Jednou zapsanou hodnotu už nepřepisuje, ať zůstane, co si majitel nastavil.
     */
    private function rememberHotelId(?string $hotelId): void
    {
        if ($hotelId === null || (string) $this->settings->getString(BookingHotelId::SETTING_KEY) !== '') {
            return;
        }

        $this->settings->set(BookingHotelId::SETTING_KEY, $hotelId, 'Booking.com: ID ubytování pro odkaz do extranetu.');
    }

    private function upsert(BookingTriggerData $data): Reservation
    {
        $reservation = $this->reservations->findByExternalId(Channel::BOOKING, $data->reservationId)
            ?? new Reservation(Channel::BOOKING, $data->checkIn);

        $isNew = $reservation->getId() === null;
        if ($isNew) {
            $reservation->setExternalId($data->reservationId);
            $this->em->persist($reservation);
            $this->notifier->notify(OwnerNotificationType::NEW_RESERVATION, $reservation);
        }

        // Booking e-mail neobsahuje žádné údaje hosta — zůstává needs_details
        // dokud je majitelka nedoplní z extranetu. checkIn aktualizujeme jen
        // dokud nebyla rezervace potvrzena, aby se nepřepisovala ručně opravená data.
        if ($isNew || $reservation->getStatus() === ReservationStatus::NEEDS_DETAILS) {
            $reservation->setCheckIn($data->checkIn);
        }

        return $reservation;
    }
}
