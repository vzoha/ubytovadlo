<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentSource;
use App\Repository\PaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Příchozí platba zachycená z bankovní notifikace. Source of truth pro "peníze dorazily" —
 * eviduje se i platba, kterou se nepodařilo navázat na rezervaci (k pozdější reconciliation).
 * Idempotence přes emailMessageId (jedna notifikace = jedna platba).
 */
#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payment')]
#[ORM\UniqueConstraint(name: 'uniq_payment_email_message_id', columns: ['email_message_id'])]
#[ORM\Index(name: 'idx_payment_variable_symbol', columns: ['variable_symbol'])]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: PaymentSource::class)]
    private PaymentSource $source;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $variableSymbol = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $constantSymbol = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $counterpartyAccount = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(length: 255)]
    private string $emailMessageId;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Reservation $reservation = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $invoice = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        PaymentSource $source,
        string $amount,
        string $currency,
        \DateTimeImmutable $receivedAt,
        string $emailMessageId,
    ) {
        $this->source = $source;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->receivedAt = $receivedAt;
        $this->emailMessageId = $emailMessageId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): PaymentSource
    {
        return $this->source;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getVariableSymbol(): ?string
    {
        return $this->variableSymbol;
    }

    public function setVariableSymbol(?string $variableSymbol): self
    {
        $this->variableSymbol = $variableSymbol;

        return $this;
    }

    public function getConstantSymbol(): ?string
    {
        return $this->constantSymbol;
    }

    public function setConstantSymbol(?string $constantSymbol): self
    {
        $this->constantSymbol = $constantSymbol;

        return $this;
    }

    public function getCounterpartyAccount(): ?string
    {
        return $this->counterpartyAccount;
    }

    public function setCounterpartyAccount(?string $counterpartyAccount): self
    {
        $this->counterpartyAccount = $counterpartyAccount;

        return $this;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getEmailMessageId(): string
    {
        return $this->emailMessageId;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function setReservation(?Reservation $reservation): self
    {
        $this->reservation = $reservation;

        return $this;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(?Invoice $invoice): self
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isMatched(): bool
    {
        return $this->reservation !== null;
    }
}
