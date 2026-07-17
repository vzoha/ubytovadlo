<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email\Handler;

use App\Email\AirbnbReservationParser;
use App\Email\Dto\AirbnbParsedReservation;
use App\Email\EmailMessage;
use App\Entity\EmailLog;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\ConnectorType;
use App\Enum\OwnerNotificationType;
use App\Enum\ReservationStatus;
use App\Formatting\Money;
use App\Notification\OwnerNotifier;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Airbnb potvrzení rezervace → založí/aktualizuje rezervaci s údaji z e-mailu.
 * Airbnb neposílá adresu hosta, takže rezervace zůstává `needs_details`.
 */
final class AirbnbReservationHandler implements EmailHandler
{
    public function __construct(
        private readonly AirbnbReservationParser $parser,
        private readonly ReservationRepository $reservations,
        private readonly OwnerNotifier $notifier,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function supports(EmailMessage $email): bool
    {
        return $this->parser->supports($email);
    }

    public function connectorType(): ConnectorType
    {
        return ConnectorType::AIRBNB;
    }

    public function handle(EmailMessage $email, EmailLog $log): void
    {
        $log->markProcessed($this->upsert($this->parser->parse($email)));
    }

    private function upsert(AirbnbParsedReservation $data): Reservation
    {
        $reservation = $this->reservations->findByExternalId(Channel::AIRBNB, $data->confirmationCode)
            ?? new Reservation(Channel::AIRBNB, $data->checkIn);

        $isNew = $reservation->getId() === null;
        if ($isNew) {
            $reservation->setExternalId($data->confirmationCode);
            $this->em->persist($reservation);
            $this->notifier->notify(OwnerNotificationType::NEW_RESERVATION, $reservation);
        }

        // Data z e-mailu aplikujeme jen na novou rezervaci nebo když ještě
        // nebyla majitelkou potvrzena (NEEDS_DETAILS). Tím respektujeme
        // ruční úpravy provedené v UI po prvním importu.
        if ($isNew || $reservation->getStatus() === ReservationStatus::NEEDS_DETAILS) {
            $reservation->setCheckIn($data->checkIn);
            $reservation->setCheckOut($data->checkOut);
            $reservation->setCheckInTime($data->checkInTime);
            $reservation->setCheckOutTime($data->checkOutTime);
            $reservation->setGuestsAdult($data->guestsAdult);
            $reservation->setGuestsChild($data->guestsChild);
            $reservation->setGuestsInfant($data->guestsInfant);
            $reservation->setGuestName($data->guestName);
            $reservation->setGuestRegion($data->guestRegion);

            if ($data->priceTotal !== null) {
                $reservation->setPriceTotal(Money::normalize($data->priceTotal));
                $reservation->setPriceCurrency('CZK');
            }
            if ($data->hostCommission !== null) {
                $reservation->setCommissionAmount(Money::normalize($data->hostCommission));
                $reservation->setCommissionCurrency('CZK');
            }
            if ($data->netPayout !== null) {
                $reservation->setNetPayout(Money::normalize($data->netPayout));
            }
            if ($data->hasPet) {
                $reservation->setHasPet(true);
            }
            if ($data->petsNote !== null) {
                $reservation->setPetsNote($data->petsNote);
            }
        }

        // Airbnb e-mail nedává adresu, takže zůstává needs_details, dokud ji nedoplníme.
        if ($reservation->getStatus() === ReservationStatus::NEEDS_DETAILS && !$reservation->getGuestAddress()->isEmpty()) {
            $reservation->setStatus(ReservationStatus::CONFIRMED);
        }

        return $reservation;
    }
}
