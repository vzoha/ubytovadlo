<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Customer;

use App\Entity\Embeddable\GuestContact;
use App\ValueObject\PhoneNumber;

/**
 * Údaje, podle kterých se host pozná napříč pobyty: vlastní e-mail (malými
 * písmeny) a telefon, pokud je v E.164. Adresa portálu sem nepatří — Booking
 * ji dává každé rezervaci jinou. Telefon, který `PhoneNumber` nepřijme, taky ne:
 * u OTA to bývá proxy číslo a spojilo by cizí lidi.
 */
final readonly class CustomerKey
{
    private function __construct(
        public ?string $email,
        public ?string $phone,
    ) {
    }

    public static function fromContact(GuestContact $contact): self
    {
        $email = $contact->getEmail();

        return new self(
            $email !== null ? mb_strtolower($email) : null,
            PhoneNumber::tryFromString($contact->getPhone())?->e164(),
        );
    }

    public function isEmpty(): bool
    {
        return $this->email === null && $this->phone === null;
    }
}
