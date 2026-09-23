<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity\Embeddable;

use App\Formatting\Text;
use Doctrine\ORM\Mapping as ORM;

/**
 * Firemní identita protistrany — bez ní se fakturuje fyzické osobě.
 * Prázdné řetězce se ukládají jako null.
 */
#[ORM\Embeddable]
final class BillingIdentity
{
    #[ORM\Column(length: 255, nullable: true)]
    private readonly ?string $companyName;

    #[ORM\Column(length: 16, nullable: true)]
    private readonly ?string $ico;

    #[ORM\Column(length: 32, nullable: true)]
    private readonly ?string $dic;

    public function __construct(?string $companyName = null, ?string $ico = null, ?string $dic = null)
    {
        $this->companyName = Text::nullIfBlank($companyName);
        $this->ico = Text::nullIfBlank($ico);
        $this->dic = Text::nullIfBlank($dic);
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function getIco(): ?string
    {
        return $this->ico;
    }

    public function getDic(): ?string
    {
        return $this->dic;
    }

    public function withCompanyName(?string $companyName): self
    {
        return new self($companyName, $this->ico, $this->dic);
    }

    public function withIco(?string $ico): self
    {
        return new self($this->companyName, $ico, $this->dic);
    }

    public function withDic(?string $dic): self
    {
        return new self($this->companyName, $this->ico, $dic);
    }

    /** Fakturuje se firmě, jen když známe její název — IČO samo nestačí. */
    public function isCompany(): bool
    {
        return $this->companyName !== null;
    }

    public function isEmpty(): bool
    {
        return $this->companyName === null && $this->ico === null && $this->dic === null;
    }

    public function equals(self $other): bool
    {
        return $this->companyName === $other->companyName
            && $this->ico === $other->ico
            && $this->dic === $other->dic;
    }
}
