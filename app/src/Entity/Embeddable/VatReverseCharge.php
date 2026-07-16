<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity\Embeddable;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Reverse-charge DPH z provize OTA (identifikovaná osoba § 6h ZDPH).
 *
 * Neměnný snímek přepočtu k datu přijetí služby (DUZP): `base` = provize
 * převedená kurzem ČNB (u CZK provize = provize beze změny), `amount` = base ×
 * 21 %, bez nároku na odpočet. `cnbRate`/`cnbRateDate` jsou vyplněné jen u provize
 * v cizí měně. Stored `cnbRate` slouží i k přepočtu ceny/provize do CZK jinde.
 */
#[ORM\Embeddable]
final class VatReverseCharge
{
    public function __construct(
        #[ORM\Column(name: 'vat_duzp', type: Types::DATE_IMMUTABLE, nullable: true)]
        private readonly ?\DateTimeImmutable $duzp = null,
        #[ORM\Column(name: 'vat_cnb_rate', type: Types::DECIMAL, precision: 14, scale: 8, nullable: true)]
        private readonly ?string $cnbRate = null,
        #[ORM\Column(name: 'vat_cnb_rate_date', type: Types::DATE_IMMUTABLE, nullable: true)]
        private readonly ?\DateTimeImmutable $cnbRateDate = null,
        #[ORM\Column(name: 'vat_base_czk', type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
        private readonly ?string $baseCzk = null,
        #[ORM\Column(name: 'vat_amount_czk', type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
        private readonly ?string $amountCzk = null,
    ) {
    }

    public function getDuzp(): ?\DateTimeImmutable
    {
        return $this->duzp;
    }

    public function getCnbRate(): ?string
    {
        return $this->cnbRate;
    }

    public function getCnbRateDate(): ?\DateTimeImmutable
    {
        return $this->cnbRateDate;
    }

    public function getBaseCzk(): ?string
    {
        return $this->baseCzk;
    }

    public function getAmountCzk(): ?string
    {
        return $this->amountCzk;
    }
}
