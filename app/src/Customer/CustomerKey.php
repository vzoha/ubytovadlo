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

/**
 * Údaje, podle kterých se host pozná napříč pobyty: vlastní e-mail (malými
 * písmeny) a telefon, pokud je v E.164. Adresa portálu sem nepatří — Booking
 * ji dává každé rezervaci jinou. Telefon, který nejde naparsovat, taky ne:
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
        $phone = $contact->getPhone();

        return new self(
            $email !== null ? mb_strtolower($email) : null,
            $phone !== null && preg_match('/^\+\d{6,15}$/', $phone) === 1 ? $phone : null,
        );
    }

    public function isEmpty(): bool
    {
        return $this->email === null && $this->phone === null;
    }
}
