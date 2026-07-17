<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity\Embeddable;

use App\ValueObject\PhoneNumber;
use Doctrine\ORM\Mapping as ORM;

/**
 * Kontakt na hosta. Telefon se ukládá v E.164, když ho jde naparsovat —
 * jinak tak, jak dorazil (u OTA je to leckdy proxy číslo nebo nesmysl).
 * Prázdné řetězce se ukládají jako null.
 */
#[ORM\Embeddable]
final class GuestContact
{
    #[ORM\Column(length: 255, nullable: true)]
    private readonly ?string $email;

    #[ORM\Column(length: 64, nullable: true)]
    private readonly ?string $phone;

    public function __construct(?string $email = null, ?string $phone = null)
    {
        $this->email = self::normalize($email);
        $this->phone = self::normalizePhone($phone);
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function withEmail(?string $email): self
    {
        return new self($email, $this->phone);
    }

    public function withPhone(?string $phone): self
    {
        return new self($this->email, $phone);
    }

    /** Bez e-mailu nemáme kam poslat fakturu ani zprávy hostovi. */
    public function hasEmail(): bool
    {
        return $this->email !== null;
    }

    public function isEmpty(): bool
    {
        return $this->email === null && $this->phone === null;
    }

    public function equals(self $other): bool
    {
        return $this->email === $other->email && $this->phone === $other->phone;
    }

    private static function normalize(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function normalizePhone(?string $value): ?string
    {
        $phone = PhoneNumber::tryFromString($value);

        return $phone !== null ? $phone->e164() : self::normalize($value);
    }
}
