<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\GuestMail;

use App\Entity\Customer;
use App\Entity\GuestEmail;
use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Repository\CustomerRepository;
use App\Repository\GuestEmailRepository;
use App\Repository\GuestMessageRepository;
use App\Repository\ReservationRepository;

/**
 * Přiřadí e-mail hostovi podle adresy a rezervaci jen tehdy, když je jasné
 * kterou: navazuje na vlákno se známou rezervací, cituje kód rezervace hosta,
 * nebo host má jedinou rezervaci, které se zpráva časově může týkat.
 * Chybné přiřazení škodí víc než žádné — při pochybnosti zůstane jen host.
 */
class GuestEmailMatcher
{
    /** Po odjezdu ještě chodí poděkování a zapomenuté věci. */
    private const AFTER_CHECKOUT_DAYS = 14;

    /** Kratší kódy (číslo rezervace z webu) se v textu shodují s cenami a daty. */
    private const MIN_CODE_LENGTH = 6;

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly ReservationRepository $reservations,
        private readonly GuestEmailRepository $emails,
        private readonly GuestMessageRepository $sentMessages,
    ) {
    }

    public function match(GuestEmail $email): void
    {
        $customer = $email->getCustomer() ?? $this->customerOf($email);
        $reservation = $this->reservationFromThread($email);
        if ($reservation === null && $customer !== null) {
            $stays = array_values(array_filter(
                $this->reservations->findAllOfCustomer($customer),
                static fn (Reservation $r): bool => $r->getStatus() !== ReservationStatus::CANCELLED,
            ));
            $reservation = self::byQuotedCode($email, $stays) ?? self::byTime($email, $stays);
        }

        $email->completeMatch($reservation?->getCustomer() ?? $customer, $reservation);
    }

    private function customerOf(GuestEmail $email): ?Customer
    {
        $address = $email->getCounterpartEmail();

        return $address !== null ? ($this->customers->findByEmail($address)[0] ?? null) : null;
    }

    private function reservationFromThread(GuestEmail $email): ?Reservation
    {
        $threadIds = $email->getThreadIds();
        foreach ($this->emails->findByMessageIds($threadIds) as $earlier) {
            if ($earlier->getReservation() !== null) {
                return $earlier->getReservation();
            }
        }

        return $this->sentMessages->findReservationByMessageIds($threadIds);
    }

    /** @param list<Reservation> $stays */
    private static function byQuotedCode(GuestEmail $email, array $stays): ?Reservation
    {
        $haystack = mb_strtoupper($email->getSubject() . "\n" . $email->getBody());
        foreach ($stays as $stay) {
            foreach ([$stay->getExternalId(), $stay->getMotopressExternalId()] as $code) {
                $code = mb_strtoupper(trim((string) $code));
                if (mb_strlen($code) >= self::MIN_CODE_LENGTH && preg_match('/(?<![A-Z0-9])' . preg_quote($code, '/') . '(?![A-Z0-9])/u', $haystack) === 1) {
                    return $stay;
                }
            }
        }

        return null;
    }

    /**
     * Pobyt, který právě běží, teprve přijde, nebo nedávno skončil — jen když je takový jediný.
     *
     * @param list<Reservation> $stays
     */
    private static function byTime(GuestEmail $email, array $stays): ?Reservation
    {
        $cutoff = $email->getSentAt()->modify(sprintf('-%d days', self::AFTER_CHECKOUT_DAYS));
        $relevant = array_values(array_filter(
            $stays,
            static fn (Reservation $r): bool => ($r->getCheckOut() ?? $r->getCheckIn()) >= $cutoff,
        ));

        return \count($relevant) === 1 ? $relevant[0] : null;
    }
}
