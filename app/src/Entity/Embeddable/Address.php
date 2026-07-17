<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity\Embeddable;

use Doctrine\ORM\Mapping as ORM;

/**
 * Poštovní adresa — sídlo/bydliště protistrany. Prázdné řetězce se ukládají
 * jako null, země se drží jako ISO 3166-1 alpha-2 velkými písmeny ("CZ", "DE").
 */
#[ORM\Embeddable]
final class Address
{
    #[ORM\Column(length: 255, nullable: true)]
    private readonly ?string $street;

    #[ORM\Column(length: 128, nullable: true)]
    private readonly ?string $city;

    #[ORM\Column(length: 16, nullable: true)]
    private readonly ?string $zip;

    #[ORM\Column(length: 2, nullable: true)]
    private readonly ?string $country;

    public function __construct(?string $street = null, ?string $city = null, ?string $zip = null, ?string $country = null)
    {
        $this->street = self::normalize($street);
        $this->city = self::normalize($city);
        $this->zip = self::normalize($zip);
        $this->country = self::normalizeCountry($country);
    }

    public static function empty(): self
    {
        return new self();
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function getZip(): ?string
    {
        return $this->zip;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function withStreet(?string $street): self
    {
        return new self($street, $this->city, $this->zip, $this->country);
    }

    public function withCity(?string $city): self
    {
        return new self($this->street, $city, $this->zip, $this->country);
    }

    public function withZip(?string $zip): self
    {
        return new self($this->street, $this->city, $zip, $this->country);
    }

    public function withCountry(?string $country): self
    {
        return new self($this->street, $this->city, $this->zip, $country);
    }

    public function equals(self $other): bool
    {
        return $this->street === $other->street
            && $this->city === $other->city
            && $this->zip === $other->zip
            && $this->country === $other->country;
    }

    /** Adresa bez ulice, města i PSČ je prázdná — samotná země za adresu nestačí. */
    public function isEmpty(): bool
    {
        return $this->street === null && $this->city === null && $this->zip === null;
    }

    /** Jednořádkový zápis "Ulice 1, 370 01 Město", nebo null u prázdné adresy. */
    public function format(): ?string
    {
        if ($this->isEmpty()) {
            return null;
        }
        $cityZip = trim(($this->zip ?? '') . ' ' . ($this->city ?? ''));
        $parts = array_filter([$this->street, $cityZip !== '' ? $cityZip : null], static fn (?string $p): bool => $p !== null && $p !== '');

        return implode(', ', $parts);
    }

    private static function normalize(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function normalizeCountry(?string $value): ?string
    {
        $normalized = self::normalize($value);

        return $normalized === null ? null : strtoupper($normalized);
    }
}
