<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\IncomeSource;
use App\Enum\ReceiptOrigin;
use App\Repository\ReservationReceiptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dílčí reálně přijatá platba rezervace — jeden řádek na jednu platební událost
 * (záloha, doplatek, OTA výplata, odhad). Na rozdíl od původního agregátu drží
 * každá platba **vlastní datum přijetí**, takže záloha přijatá v jiném měsíci než
 * doplatek se v měsíčním cashflow zaúčtuje správně.
 *
 * Upsertuje se dle původu (originType + originId): recompute přegeneruje
 * automatické receipty (faktury/platby/odhad), ruční (`manuallyOverridden`)
 * nechává být.
 */
#[ORM\Entity(repositoryClass: ReservationReceiptRepository::class)]
#[ORM\Table(name: 'reservation_receipt')]
#[ORM\UniqueConstraint(name: 'uniq_receipt_origin', columns: ['reservation_id', 'origin_type', 'origin_id'])]
#[ORM\Index(name: 'idx_receipt_received_on', columns: ['received_on'])]
class ReservationReceipt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Reservation $reservation;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $amountCzk;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Account $account = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $receivedOn = null;

    #[ORM\Column(length: 16, enumType: IncomeSource::class)]
    private IncomeSource $source;

    #[ORM\Column(length: 16, enumType: ReceiptOrigin::class)]
    private ReceiptOrigin $originType;

    /** Id zdrojové faktury/platby, nebo 0 pro singletony (odhad/výplata/ruční). */
    #[ORM\Column(type: Types::INTEGER)]
    private int $originId = 0;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $manuallyOverridden = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Reservation $reservation, string $amountCzk, IncomeSource $source, ReceiptOrigin $originType, int $originId = 0)
    {
        $this->reservation = $reservation;
        $this->amountCzk = $amountCzk;
        $this->source = $source;
        $this->originType = $originType;
        $this->originId = $originId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): Reservation
    {
        return $this->reservation;
    }

    public function getAmountCzk(): string
    {
        return $this->amountCzk;
    }

    public function setAmountCzk(string $amountCzk): self
    {
        $this->amountCzk = $amountCzk;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): self
    {
        $this->account = $account;

        return $this;
    }

    public function getReceivedOn(): ?\DateTimeImmutable
    {
        return $this->receivedOn;
    }

    public function setReceivedOn(?\DateTimeImmutable $receivedOn): self
    {
        $this->receivedOn = $receivedOn;

        return $this;
    }

    public function getSource(): IncomeSource
    {
        return $this->source;
    }

    public function setSource(IncomeSource $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getOriginType(): ReceiptOrigin
    {
        return $this->originType;
    }

    public function getOriginId(): int
    {
        return $this->originId;
    }

    /** Idempotentní klíč původu — jediná autorita formátu, sdílená s ReceiptTarget. */
    public function originKey(): string
    {
        return self::makeOriginKey($this->originType, $this->originId);
    }

    public static function makeOriginKey(ReceiptOrigin $originType, int $originId): string
    {
        return $originType->value . ':' . $originId;
    }

    public function isManuallyOverridden(): bool
    {
        return $this->manuallyOverridden;
    }

    public function setManuallyOverridden(bool $manuallyOverridden): self
    {
        $this->manuallyOverridden = $manuallyOverridden;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
