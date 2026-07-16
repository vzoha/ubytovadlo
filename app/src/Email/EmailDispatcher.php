<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email;

use App\Cashflow\IncomeUpserter;
use App\Connector\ConnectorManager;
use App\Email\Dto\AirbnbParsedReservation;
use App\Email\Dto\AirbnbPayoutData;
use App\Email\Dto\BookingTriggerData;
use App\Entity\EmailLog;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\ConnectorType;
use App\Enum\OwnerNotificationType;
use App\Enum\ReservationStatus;
use App\Formatting\Money;
use App\Notification\OwnerNotifier;
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
        private readonly IncomeUpserter $incomeUpserter,
        private readonly OwnerNotifier $notifier,
        private readonly ConnectorManager $connectors,
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

        $connectorType = $this->connectorType($email);
        if ($connectorType !== null) {
            if (!$this->connectors->isEnabled($connectorType)) {
                $log->markIgnored(sprintf('Konektor „%s" je vypnutý', $connectorType->label()));

                return $log;
            }
            // Zpráva z tohoto zdroje dorazila → poslední aktivita konektoru (i když
            // ji nakonec ignorujeme, transport žije — podklad pro „nechodí data").
            $this->connectors->recordActivity($connectorType, $email->date);
        }

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

        $reservation->setPayoutAmount(Money::normalize($data->payoutAmount));
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

        // Výplata (net po provizi) je reálný příjem na účet — přepočítej ReservationIncome.
        $this->incomeUpserter->recompute($reservation);

        return $reservation;
    }

    /**
     * Kterému konektoru zpráva patří (podle odesílatele), nebo null když žádnému.
     * Stejné pořadí jako zpracování — Airbnb rezervace i výplata spadají pod Airbnb,
     * Booking trigger i měsíční faktura pod Booking, platební notifikace pod banku.
     * Poller si tím ověří, jestli zprávu vůbec zpracovávat (vypnutý konektor přeskočí).
     */
    public function connectorType(EmailMessage $email): ?ConnectorType
    {
        return match (true) {
            $this->airbnbParser->supports($email), $this->airbnbPayoutParser->supports($email) => ConnectorType::AIRBNB,
            $this->bookingParser->supports($email), $this->bookingInvoiceImporter->supports($email) => ConnectorType::BOOKING,
            $this->csPaymentParser->supports($email) => ConnectorType::BANK_CS,
            default => null,
        };
    }

    private function upsertBooking(BookingTriggerData $data): Reservation
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
