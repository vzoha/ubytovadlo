<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Customer\CustomerKey;
use App\Formatting\Text;
use App\Repository\CustomerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Zákazník — ten, kdo rezervuje, napříč všemi svými pobyty. Údaje na rezervaci
 * zůstávají snímkem ke dni pobytu; tady je jen to, podle čeho hosta poznáme
 * příště. E-mail se drží malými písmeny, telefon v E.164 — ve tvaru, ve kterém
 * se podle nich páruje (`CustomerKey`).
 */
#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customer')]
#[ORM\Index(name: 'idx_customer_email', columns: ['email'])]
#[ORM\Index(name: 'idx_customer_phone', columns: ['phone'])]
class Customer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $displayName;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $phone = null;

    /** Co si o hostovi pamatovat příště — pes, postýlka, oblíbený pokoj. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(?string $displayName, CustomerKey $key)
    {
        $this->displayName = Text::nullIfBlank($displayName);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->absorb($key);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): self
    {
        $this->displayName = Text::nullIfBlank($displayName);
        $this->touch();

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = Text::nullIfBlank($note);
        $this->touch();

        return $this;
    }

    /**
     * Převezme údaje sloučeného zákazníka: chybějící jméno a kontakt doplní,
     * poznámky spojí. Vlastní vyplněné údaje nechává.
     */
    public function mergeFrom(self $other): void
    {
        $this->displayName ??= $other->displayName;
        $this->email ??= $other->email;
        $this->phone ??= $other->phone;
        $notes = array_filter([$this->note, $other->note], static fn (?string $n): bool => $n !== null);
        $this->note = $notes === [] ? null : implode("\n\n", array_unique($notes));
        $this->touch();
    }

    /**
     * Doplní chybějící e-mail nebo telefon z dalšího pobytu. Vyplněný údaj
     * nepřepisuje — o tom, který kontakt platí, rozhoduje ubytovatel.
     *
     * @return bool zda se něco změnilo
     */
    public function absorb(CustomerKey $key): bool
    {
        $changed = false;
        if ($this->email === null && $key->email !== null) {
            $this->email = $key->email;
            $changed = true;
        }
        if ($this->phone === null && $key->phone !== null) {
            $this->phone = $key->phone;
            $changed = true;
        }
        if ($changed) {
            $this->touch();
        }

        return $changed;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
