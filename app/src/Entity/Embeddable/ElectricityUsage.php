<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity\Embeddable;

use App\Enum\ElectricitySource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Spotřeba elektřiny za pobyt (evidenční — hostům se neúčtuje, je v ceně).
 * Neměnný snímek: zdroj je pevně svázaný s tím, jak vznikl —
 * `measured()` = pobyt pokrytý vlastními odečty před+po, `allocated()` = podíl
 * rozpočítaný `ElectricityAllocatorem`.
 */
#[ORM\Embeddable]
final class ElectricityUsage
{
    public function __construct(
        #[ORM\Column(name: 'vt_kwh', type: Types::INTEGER, nullable: true)]
        private readonly ?int $vtKwh = null,
        #[ORM\Column(name: 'nt_kwh', type: Types::INTEGER, nullable: true)]
        private readonly ?int $ntKwh = null,
        #[ORM\Column(name: 'electricity_source', length: 16, enumType: ElectricitySource::class, nullable: true)]
        private readonly ?ElectricitySource $source = null,
    ) {
    }

    public static function measured(?int $vtKwh, ?int $ntKwh): self
    {
        return new self($vtKwh, $ntKwh, ElectricitySource::MEASURED);
    }

    public static function allocated(?int $vtKwh, ?int $ntKwh): self
    {
        return new self($vtKwh, $ntKwh, ElectricitySource::ALLOCATED);
    }

    public function getVtKwh(): ?int
    {
        return $this->vtKwh;
    }

    public function getNtKwh(): ?int
    {
        return $this->ntKwh;
    }

    public function getSource(): ?ElectricitySource
    {
        return $this->source;
    }

    /** Celková spotřeba (VT + NT), nebo null když není znám ani jeden odečet. */
    public function getTotalKwh(): ?int
    {
        if ($this->vtKwh === null && $this->ntKwh === null) {
            return null;
        }

        return ($this->vtKwh ?? 0) + ($this->ntKwh ?? 0);
    }
}
