<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Booking;

use App\Entity\Reservation;

final class BookingExtranetData
{
    public ?\DateTimeImmutable $checkIn = null;
    public ?\DateTimeImmutable $checkOut = null;
    public ?int $guestsAdult = null;
    public ?int $guestsChild = null;
    public ?string $priceTotal = null;
    public ?string $priceCurrency = null;
    public ?string $commissionAmount = null;
    public ?string $commissionCurrency = null;
    public ?string $externalId = null;
    public ?string $guestName = null;
    public ?string $guestEmail = null;
    public ?string $guestPhone = null;
    public ?string $guestStreet = null;
    public ?string $guestCity = null;
    public ?string $guestZip = null;
    public ?string $guestCountry = null;
    public ?string $notes = null;
    public ?bool $hasPet = null;
    public ?string $petsNote = null;
    public ?bool $needsBabyCot = null;

    public function applyTo(Reservation $reservation): void
    {
        if ($this->checkIn !== null) {
            $reservation->setCheckIn($this->checkIn);
        }
        if ($this->checkOut !== null) {
            $reservation->setCheckOut($this->checkOut);
        }
        if ($this->guestsAdult !== null) {
            $reservation->setGuestsAdult($this->guestsAdult);
        }
        if ($this->guestsChild !== null) {
            $reservation->setGuestsChild($this->guestsChild);
        }
        if ($this->priceTotal !== null) {
            $reservation->setPriceTotal($this->priceTotal);
        }
        if ($this->priceCurrency !== null) {
            $reservation->setPriceCurrency($this->priceCurrency);
        }
        if ($this->commissionAmount !== null) {
            $reservation->setCommissionAmount($this->commissionAmount);
            $reservation->setCommissionCurrency($this->commissionCurrency);
        }
        if ($this->externalId !== null && $reservation->getExternalId() === null) {
            $reservation->setExternalId($this->externalId);
        }
        if ($this->guestName !== null) {
            $reservation->setGuestName($this->guestName);
        }
        $contact = $reservation->getGuestContact();
        if ($this->guestEmail !== null && $contact->getEmail() === null) {
            $contact = $contact->withEmail($this->guestEmail);
        }
        if ($this->guestPhone !== null && $contact->getPhone() === null) {
            $contact = $contact->withPhone($this->guestPhone);
        }
        $reservation->setGuestContact($contact);
        $address = $reservation->getGuestAddress();
        if ($this->guestStreet !== null) {
            $address = $address->withStreet($this->guestStreet);
        }
        if ($this->guestCity !== null) {
            $address = $address->withCity($this->guestCity);
        }
        if ($this->guestZip !== null) {
            $address = $address->withZip($this->guestZip);
        }
        if ($this->guestCountry !== null) {
            $address = $address->withCountry($this->guestCountry);
        }
        $reservation->setGuestAddress($address);
        if ($this->notes !== null) {
            $existing = $reservation->getNotes();
            $reservation->setNotes($existing !== null && $existing !== '' ? $existing . "\n" . $this->notes : $this->notes);
        }
        if ($this->hasPet === true) {
            $reservation->setHasPet(true);
        }
        if ($this->petsNote !== null && $this->petsNote !== '') {
            $reservation->setPetsNote($this->petsNote);
        }
        if ($this->needsBabyCot === true) {
            $reservation->setNeedsBabyCot(true);
        }
    }
}
