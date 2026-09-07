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
 * Kontakt na hosta. Adresa portálu (`@guest.booking.com` a spol.) se drží zvlášť
 * od vlastní adresy hosta: doručuje do chatu portálu, má omezenou životnost a
 * nemusí propustit přílohu. Telefon se ukládá v E.164, když ho jde naparsovat —
 * jinak tak, jak dorazil (u OTA je to leckdy proxy číslo nebo nesmysl).
 * Prázdné řetězce se ukládají jako null.
 */
#[ORM\Embeddable]
final class GuestContact
{
    /** Domény, za kterými stojí schránka portálu, ne hosta. */
    private const array PORTAL_DOMAINS = ['guest.booking.com', 'guest.airbnb.com'];

    #[ORM\Column(length: 255, nullable: true)]
    private readonly ?string $email;

    #[ORM\Column(length: 64, nullable: true)]
    private readonly ?string $phone;

    #[ORM\Column(length: 255, nullable: true)]
    private readonly ?string $portalEmail;

    public function __construct(?string $email = null, ?string $phone = null, ?string $portalEmail = null)
    {
        $email = self::normalize($email);
        $portal = self::normalize($portalEmail);

        // Adresu si roztřídíme sami, ať na to importy ani formuláře nemusí myslet.
        if ($email !== null && self::isPortalAddress($email)) {
            $portal ??= $email;
            $email = null;
        }

        $this->email = $email;
        $this->phone = self::normalizePhone($phone);
        $this->portalEmail = $portal;
    }

    /** Vlastní adresa hosta; adresa portálu sem nepatří. */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /** Adresa portálu, která doručuje do chatu jeho aplikace. */
    public function getPortalEmail(): ?string
    {
        return $this->portalEmail;
    }

    /** Adresa, na kterou zpráva reálně odejde — vlastní má přednost před portálem. */
    public function getDeliveryEmail(): ?string
    {
        return $this->email ?? $this->portalEmail;
    }

    /** Píšeme jen na adresu portálu — zpráva skončí v chatu jeho aplikace. */
    public function deliversToPortal(): bool
    {
        return $this->email === null && $this->portalEmail !== null;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function withEmail(?string $email): self
    {
        return new self($email, $this->phone, $this->portalEmail);
    }

    public function withPortalEmail(?string $portalEmail): self
    {
        return new self($this->email, $this->phone, $portalEmail);
    }

    public function withPhone(?string $phone): self
    {
        return new self($this->email, $phone, $this->portalEmail);
    }

    /** Bez e-mailu nemáme kam poslat fakturu ani zprávy hostovi. */
    public function hasEmail(): bool
    {
        return $this->getDeliveryEmail() !== null;
    }

    public function isEmpty(): bool
    {
        return $this->email === null && $this->phone === null && $this->portalEmail === null;
    }

    public function equals(self $other): bool
    {
        return $this->email === $other->email
            && $this->phone === $other->phone
            && $this->portalEmail === $other->portalEmail;
    }

    /** Adresa patří schránce portálu, ne hostovi. */
    private static function isPortalAddress(string $email): bool
    {
        $domain = mb_strtolower(substr(strrchr($email, '@') ?: '', 1));

        return in_array($domain, self::PORTAL_DOMAINS, true);
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
