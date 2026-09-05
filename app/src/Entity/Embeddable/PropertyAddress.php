<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity\Embeddable;

use App\Formatting\CzechZip;
use Doctrine\ORM\Mapping as ORM;

/**
 * Adresa ubytovacího objektu v členění, které vyžaduje hlášení na Ubyport:
 * okres, obec, část obce, ulice, číslo popisné a orientační, PSČ. Na vesnici
 * bez ulic nese adresu část obce („Lniště 30, 374 01 Slavče"), ve městě se
 * uvádí vedle ulice; čísla odděluje lomítko.
 *
 * Prázdné řetězce se ukládají jako null, aby „nevyplněno" mělo jednu podobu.
 */
#[ORM\Embeddable]
final class PropertyAddress
{
    #[ORM\Column(length: 128, nullable: true)]
    private readonly ?string $okres;

    #[ORM\Column(length: 128, nullable: true)]
    private readonly ?string $obec;

    #[ORM\Column(length: 128, nullable: true)]
    private readonly ?string $castObce;

    #[ORM\Column(length: 128, nullable: true)]
    private readonly ?string $ulice;

    #[ORM\Column(length: 16, nullable: true)]
    private readonly ?string $cp;

    #[ORM\Column(length: 16, nullable: true)]
    private readonly ?string $co;

    #[ORM\Column(length: 8, nullable: true)]
    private readonly ?string $psc;

    public function __construct(
        ?string $okres = null,
        ?string $obec = null,
        ?string $castObce = null,
        ?string $ulice = null,
        ?string $cp = null,
        ?string $co = null,
        ?string $psc = null,
    ) {
        $this->okres = self::normalize($okres);
        $this->obec = self::normalize($obec);
        $this->castObce = self::normalize($castObce);
        $this->ulice = self::normalize($ulice);
        $this->cp = self::normalize($cp);
        $this->co = self::normalize($co);
        $this->psc = self::normalize($psc);
    }

    public static function empty(): self
    {
        return new self();
    }

    public function getOkres(): ?string
    {
        return $this->okres;
    }

    public function getObec(): ?string
    {
        return $this->obec;
    }

    public function getCastObce(): ?string
    {
        return $this->castObce;
    }

    public function getUlice(): ?string
    {
        return $this->ulice;
    }

    public function getCp(): ?string
    {
        return $this->cp;
    }

    public function getCo(): ?string
    {
        return $this->co;
    }

    public function getPsc(): ?string
    {
        return $this->psc;
    }

    /** Adresa bez obce a PSČ je prázdná — samotný okres za adresu nestačí. */
    public function isEmpty(): bool
    {
        return $this->obec === null && $this->psc === null;
    }

    /** Jednořádkový zápis „Lniště 30, 374 01 Slavče", nebo prázdný řetězec. */
    public function format(): string
    {
        $number = implode('/', array_filter([$this->cp, $this->co]));
        $street = trim(($this->ulice ?? $this->castObce ?? '') . ' ' . $number);

        $parts = array_filter([
            $street,
            $this->ulice !== null ? $this->castObce : null,
            trim((CzechZip::format($this->psc) ?? '') . ' ' . ($this->obec ?? '')),
        ]);

        return implode(', ', $parts);
    }

    private static function normalize(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
