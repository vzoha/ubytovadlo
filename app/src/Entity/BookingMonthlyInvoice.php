<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BookingMonthlyInvoiceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookingMonthlyInvoiceRepository::class)]
#[ORM\Table(name: 'booking_monthly_invoice')]
#[ORM\UniqueConstraint(name: 'uniq_invoice_number', columns: ['invoice_number'])]
#[ORM\Index(name: 'idx_period_to', columns: ['period_to'])]
class BookingMonthlyInvoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $invoiceNumber;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodTo;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $roomSales;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $commission;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $paymentFee = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalPayable;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 8, nullable: true)]
    private ?string $bookingExchangeRate = null;

    #[ORM\Column(length: 512)]
    private string $pdfPath;

    #[ORM\ManyToOne(targetEntity: EmailLog::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EmailLog $sourceEmail = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $invoiceNumber,
        \DateTimeImmutable $issuedAt,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
        string $roomSales,
        string $commission,
        string $totalPayable,
        string $pdfPath,
    ) {
        $this->invoiceNumber = $invoiceNumber;
        $this->issuedAt = $issuedAt;
        $this->periodFrom = $periodFrom;
        $this->periodTo = $periodTo;
        $this->roomSales = $roomSales;
        $this->commission = $commission;
        $this->totalPayable = $totalPayable;
        $this->pdfPath = $pdfPath;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoiceNumber(): string
    {
        return $this->invoiceNumber;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getPeriodFrom(): \DateTimeImmutable
    {
        return $this->periodFrom;
    }

    public function getPeriodTo(): \DateTimeImmutable
    {
        return $this->periodTo;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getRoomSales(): string
    {
        return $this->roomSales;
    }

    public function getCommission(): string
    {
        return $this->commission;
    }

    public function getPaymentFee(): string
    {
        return $this->paymentFee;
    }

    public function setPaymentFee(string $paymentFee): self
    {
        $this->paymentFee = $paymentFee;

        return $this;
    }

    public function getTotalPayable(): string
    {
        return $this->totalPayable;
    }

    public function getBookingExchangeRate(): ?string
    {
        return $this->bookingExchangeRate;
    }

    public function setBookingExchangeRate(?string $bookingExchangeRate): self
    {
        $this->bookingExchangeRate = $bookingExchangeRate;

        return $this;
    }

    public function getPdfPath(): string
    {
        return $this->pdfPath;
    }

    public function getSourceEmail(): ?EmailLog
    {
        return $this->sourceEmail;
    }

    public function setSourceEmail(?EmailLog $sourceEmail): self
    {
        $this->sourceEmail = $sourceEmail;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
