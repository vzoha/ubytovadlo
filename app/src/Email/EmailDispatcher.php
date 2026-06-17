<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email;

use App\Email\Dto\AirbnbParsedReservation;
use App\Email\Dto\AirbnbPayoutData;
use App\Email\Dto\BookingTriggerData;
use App\Entity\EmailLog;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Payment\PaymentProcessor;
use App\Repository\EmailLogRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationRepository;
use App\Vat\BookingInvoiceImporter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class EmailDispatcher
{
    public function __construct(
        private readonly EmailLogRepository $emailLogs,
        private readonly ReservationRepository $reservations,
        private readonly AirbnbReservationParser $airbnbParser,
        private readonly AirbnbPayoutParser $airbnbPayoutParser,
        private readonly BookingTriggerParser $bookingParser,
        private readonly BookingInvoiceImporter $bookingInvoiceImporter,
        private readonly CsPaymentParser $csPaymentParser,
        private readonly PaymentProcessor $paymentProcessor,
        private readonly InvoiceRepository $invoices,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function dispatch(EmailMessage $email): EmailLog
    {
        $existing = $this->emailLogs->findByMessageId($email->messageId);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->em->wrapInTransaction(fn () => $this->process($email));
        } catch (UniqueConstraintViolationException) {
            $this->em->clear();
            $log = $this->emailLogs->findByMessageId($email->messageId);
            if ($log !== null) {
                return $log;
            }
            throw new \RuntimeException(sprintf('EmailLog for messageId "%s" missing after unique violation', $email->messageId));
        }
    }

    private function process(EmailMessage $email): EmailLog
    {
        $log = new EmailLog($email->messageId, $email->date);
        $log->setFromAddress($email->fromAddress);
        $log->setSubject($email->subject);
        $this->em->persist($log);

        try {
            if ($this->airbnbParser->supports($email)) {
                $reservation = $this->upsertAirbnb($this->airbnbParser->parse($email));
                $log->markProcessed($reservation);
            } elseif ($this->airbnbPayoutParser->supports($email)) {
                $data = $this->airbnbPayoutParser->parse($email);
                $reservation = $this->applyAirbnbPayout($data);
                if ($reservation !== null) {
                    $log->markProcessed($reservation);
                } else {
                    $log->markIgnored(sprintf('Payout for unknown reservation %s', $data->confirmationCode));
                }
            } elseif ($this->bookingParser->supports($email)) {
                $reservation = $this->upsertBooking($this->bookingParser->parse($email));
                $log->markProcessed($reservation);
            } elseif ($this->bookingInvoiceImporter->supports($email)) {
                $this->bookingInvoiceImporter->import($email, $log);
                $log->markProcessed();
            } elseif ($this->csPaymentParser->supports($email)) {
                $result = $this->paymentProcessor->process($this->csPaymentParser->parse($email), $email);
                if ($result->reservation !== null) {
                    $log->markProcessed($result->reservation);
                } else {
                    $log->markIgnored($result->ignoredReason);
                }
            } else {
                $log->markIgnored('No parser matched');
            }
        } catch (UniqueConstraintViolationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Email dispatch failed', [
                'messageId' => $email->messageId,
                'subject' => $email->subject,
                'exception' => $e,
            ]);
            $log->markError($e->getMessage());
        }

        return $log;
    }

    private function upsertAirbnb(AirbnbParsedReservation $data): Reservation
    {
        $reservation = $this->reservations->findByExternalId(Channel::AIRBNB, $data->confirmationCode)
            ?? new Reservation(Channel::AIRBNB, $data->checkIn);

        $isNew = $reservation->getId() === null;
        if ($isNew) {
            $reservation->setExternalId($data->confirmationCode);
            $this->em->persist($reservation);
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
                $reservation->setPriceTotal(number_format($data->priceTotal, 2, '.', ''));
                $reservation->setPriceCurrency('CZK');
            }
            if ($data->hostCommission !== null) {
                $reservation->setCommissionAmount(number_format($data->hostCommission, 2, '.', ''));
                $reservation->setCommissionCurrency('CZK');
            }
            if ($data->netPayout !== null) {
                $reservation->setNetPayout(number_format($data->netPayout, 2, '.', ''));
            }
            if ($data->hasPet) {
                $reservation->setHasPet(true);
            }
            if ($data->petsNote !== null) {
                $reservation->setPetsNote($data->petsNote);
            }
        }

        // Airbnb e-mail nedává adresu, takže zůstává needs_details, dokud ji nedoplníme.
        if ($reservation->getStatus() === ReservationStatus::NEEDS_DETAILS && $reservation->hasGuestAddress()) {
            $reservation->setStatus(ReservationStatus::CONFIRMED);
        }

        return $reservation;
    }

    /**
     * Napáruje reálnou Airbnb výplatu na rezervaci podle potvrzujícího kódu.
     * Uloží částku a datum odeslání a — pokud už existuje faktura hostovi —
     * nastaví na ní datum úhrady na den odeslání výplaty (reálný příjem peněz).
     * Vrací null, pokud rezervace zatím v DB není (potvrzovací e-mail nedorazil).
     */
    private function applyAirbnbPayout(AirbnbPayoutData $data): ?Reservation
    {
        $reservation = $this->reservations->findByExternalId(Channel::AIRBNB, $data->confirmationCode);
        if ($reservation === null) {
            return null;
        }

        $reservation->setPayoutAmount(number_format($data->payoutAmount, 2, '.', ''));
        $reservation->setPayoutSentAt($data->payoutSentAt);
        if ($data->payoutReference !== null) {
            $reservation->setPayoutReference($data->payoutReference);
        }

        // Datum úhrady na faktuře = den, kdy Airbnb peníze odeslal. Nastavujeme
        // jen na dosud neuhrazené faktury, ruční označení nepřepisujeme.
        foreach ($this->invoices->findForReservation($reservation) as $invoice) {
            if (!$invoice->isPaid()) {
                $invoice->setPaidAt($data->payoutSentAt);
            }
        }

        return $reservation;
    }

    private function upsertBooking(BookingTriggerData $data): Reservation
    {
        $reservation = $this->reservations->findByExternalId(Channel::BOOKING, $data->reservationId)
            ?? new Reservation(Channel::BOOKING, $data->checkIn);

        $isNew = $reservation->getId() === null;
        if ($isNew) {
            $reservation->setExternalId($data->reservationId);
            $this->em->persist($reservation);
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
