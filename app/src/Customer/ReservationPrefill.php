<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Customer;

use App\Entity\Customer;
use App\Entity\Embeddable\GuestContact;
use App\Entity\Reservation;
use App\Repository\ReservationRepository;

/**
 * Nová rezervace pro hosta, který tu už byl: jméno a kontakt ze zákazníka,
 * adresa, fakturační údaje a jazyk z jeho posledního pobytu. Termín, cena
 * ani doplňky se nepřenášejí — patří jen k jednomu pobytu; co si o hostovi
 * pamatovat, drží poznámka zákazníka.
 */
final class ReservationPrefill
{
    public const string ACQUISITION_SOURCE = 'Návrat';

    public function __construct(private readonly ReservationRepository $reservations)
    {
    }

    public function apply(Reservation $reservation, Customer $customer): void
    {
        $last = $this->reservations->findAllOfCustomer($customer)[0] ?? null;
        $lastContact = $last?->getGuestContact();

        $reservation->setGuestName($customer->getDisplayName() ?? $last?->getGuestName());
        $reservation->setGuestContact(new GuestContact(
            $lastContact?->getEmail() ?? $customer->getEmail(),
            $lastContact?->getPhone() ?? $customer->getPhone(),
        ));
        $reservation->setAcquisitionSource(self::ACQUISITION_SOURCE);

        if ($last === null) {
            return;
        }
        if (!$last->getGuestAddress()->isEmpty()) {
            $reservation->setGuestAddress($last->getGuestAddress());
        }
        $reservation->setGuestBilling($last->getGuestBilling());
        $reservation->setGuestLocale($last->getGuestLocale());
    }

    /**
     * Přiřadí rezervaci zákazníkovi, ze kterého se předvyplnila — pokud ve
     * formuláři zůstal tentýž host. Host bez kontaktu (Airbnb) by se jinak
     * automaticky nespároval; přepsané jméno znamená jiného člověka.
     */
    public function linkIfSameGuest(Reservation $reservation, Customer $customer): void
    {
        if (NameMatch::compatible($customer->getDisplayName(), $reservation->getGuestName())) {
            $reservation->setCustomer($customer);
        }
    }
}
