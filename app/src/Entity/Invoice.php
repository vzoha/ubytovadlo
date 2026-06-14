<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InvoiceType;
use App\Enum\PdfSource;
use App\Repository\InvoiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoice')]
#[ORM\UniqueConstraint(name: 'uniq_invoice_number', columns: ['number'])]
#[ORM\Index(name: 'idx_invoice_year', columns: ['series_year'])]
#[ORM\Index(name: 'idx_invoice_reservation', columns: ['reservation_id'])]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Pravidla: RRRR### (např. 2026012). Unikátní napříč všemi roky. */
    #[ORM\Column(length: 16)]
    private string $number;

    #[ORM\Column(name: 'series_year', type: Types::SMALLINT)]
    private int $seriesYear;

    #[ORM\Column(length: 16, enumType: InvoiceType::class)]
    private InvoiceType $type;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Reservation $reservation;

    /** Pouze pro INVOICE_TYPE::FINAL — odkaz na zálohovou fakturu, jejíž platbu odpočítáváme. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_invoice_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $parentInvoice = null;

    // === Snapshot odběratele v čase vystavení ===
    #[ORM\Column(length: 255)]
    private string $customerName;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customerStreet = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $customerCity = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $customerZip = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $customerCountry = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customerCompanyName = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $customerIco = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $customerDic = null;

    // === Částky ===
    #[ORM\Column(length: 3)]
    private string $currency = 'CZK';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalAmount = '0';

    /** Pokud byla rezervace v cizí měně (Booking EUR), zde se uloží původní částka. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $originalAmount = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $originalCurrency = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 8, nullable: true)]
    private ?string $exchangeRate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $exchangeRateDate = null;

    // === Termíny + platba ===
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dueAt;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(length: 32)]
    private string $paymentMethod = 'převodem';

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $bankAccount = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $variableSymbol = null;

    /** SPAYD payload pro QR Platbu (SPD*1.0*ACC:...*AM:...*CC:CZK*...). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $qrPayload = null;

    /** Cesta k uloženému PDF na disku (var/invoices/RRRR/RRRR###.pdf). */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $pdfPath = null;

    /** Původ PDF — EXTERNAL (importované) se nepřegenerovává. */
    #[ORM\Column(length: 16, enumType: PdfSource::class, options: ['default' => 'generated'])]
    private PdfSource $pdfSource = PdfSource::GENERATED;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /** @var Collection<int, InvoiceLine> */
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: InvoiceLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $number,
        int $seriesYear,
        InvoiceType $type,
        Reservation $reservation,
        \DateTimeImmutable $issuedAt,
        \DateTimeImmutable $dueAt,
    ) {
        $this->number = $number;
        $this->seriesYear = $seriesYear;
        $this->type = $type;
        $this->reservation = $reservation;
        $this->issuedAt = $issuedAt;
        $this->dueAt = $dueAt;
        $this->customerName = $reservation->getGuestName() ?? '';
        $this->lines = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getSeriesYear(): int
    {
        return $this->seriesYear;
    }

    public function getType(): InvoiceType
    {
        return $this->type;
    }

    public function getReservation(): Reservation
    {
        return $this->reservation;
    }

    public function getParentInvoice(): ?self
    {
        return $this->parentInvoice;
    }

    public function setParentInvoice(?self $parent): self
    {
        $this->parentInvoice = $parent;

        return $this;
    }

    public function getCustomerName(): string
    {
        return $this->customerName;
    }

    public function setCustomerName(string $name): self
    {
        $this->customerName = $name;

        return $this;
    }

    public function getCustomerStreet(): ?string
    {
        return $this->customerStreet;
    }

    public function setCustomerStreet(?string $street): self
    {
        $this->customerStreet = $street;

        return $this;
    }

    public function getCustomerCity(): ?string
    {
        return $this->customerCity;
    }

    public function setCustomerCity(?string $city): self
    {
        $this->customerCity = $city;

        return $this;
    }

    public function getCustomerZip(): ?string
    {
        return $this->customerZip;
    }

    public function setCustomerZip(?string $zip): self
    {
        $this->customerZip = $zip;

        return $this;
    }

    public function getCustomerCountry(): ?string
    {
        return $this->customerCountry;
    }

    public function setCustomerCountry(?string $country): self
    {
        $this->customerCountry = $country;

        return $this;
    }

    public function getCustomerCompanyName(): ?string
    {
        return $this->customerCompanyName;
    }

    public function setCustomerCompanyName(?string $name): self
    {
        $this->customerCompanyName = $name;

        return $this;
    }

    public function getCustomerIco(): ?string
    {
        return $this->customerIco;
    }

    public function setCustomerIco(?string $ico): self
    {
        $this->customerIco = $ico;

        return $this;
    }

    public function getCustomerDic(): ?string
    {
        return $this->customerDic;
    }

    public function setCustomerDic(?string $dic): self
    {
        $this->customerDic = $dic;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCurrencyLabel(): string
    {
        return $this->currency === 'CZK' ? 'Kč' : $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(string $totalAmount): self
    {
        $this->totalAmount = $totalAmount;

        return $this;
    }

    public function getOriginalAmount(): ?string
    {
        return $this->originalAmount;
    }

    public function setOriginalAmount(?string $amount): self
    {
        $this->originalAmount = $amount;

        return $this;
    }

    public function getOriginalCurrency(): ?string
    {
        return $this->originalCurrency;
    }

    public function setOriginalCurrency(?string $currency): self
    {
        $this->originalCurrency = $currency;

        return $this;
    }

    public function getExchangeRate(): ?string
    {
        return $this->exchangeRate;
    }

    public function setExchangeRate(?string $rate): self
    {
        $this->exchangeRate = $rate;

        return $this;
    }

    public function getExchangeRateDate(): ?\DateTimeImmutable
    {
        return $this->exchangeRateDate;
    }

    public function setExchangeRateDate(?\DateTimeImmutable $date): self
    {
        $this->exchangeRateDate = $date;

        return $this;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(\DateTimeImmutable $issuedAt): self
    {
        $this->issuedAt = $issuedAt;

        return $this;
    }

    public function getDueAt(): \DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function setDueAt(\DateTimeImmutable $dueAt): self
    {
        $this->dueAt = $dueAt;

        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): self
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function isPaid(): bool
    {
        return $this->paidAt !== null;
    }

    public function getPaymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(string $method): self
    {
        $this->paymentMethod = $method;

        return $this;
    }

    public function getBankAccount(): ?string
    {
        return $this->bankAccount;
    }

    public function setBankAccount(?string $bankAccount): self
    {
        $this->bankAccount = $bankAccount;

        return $this;
    }

    public function getVariableSymbol(): ?string
    {
        return $this->variableSymbol;
    }

    public function getDisplayVariableSymbol(): string
    {
        return $this->variableSymbol ?? $this->number;
    }

    public function setVariableSymbol(?string $vs): self
    {
        $this->variableSymbol = $vs;

        return $this;
    }

    public function getQrPayload(): ?string
    {
        return $this->qrPayload;
    }

    public function setQrPayload(?string $payload): self
    {
        $this->qrPayload = $payload;

        return $this;
    }

    public function getPdfPath(): ?string
    {
        return $this->pdfPath;
    }

    public function setPdfPath(?string $path): self
    {
        $this->pdfPath = $path;

        return $this;
    }

    public function getPdfSource(): PdfSource
    {
        return $this->pdfSource;
    }

    public function setPdfSource(PdfSource $pdfSource): self
    {
        $this->pdfSource = $pdfSource;

        return $this;
    }

    public function isExternalPdf(): bool
    {
        return $this->pdfSource === PdfSource::EXTERNAL;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    /** @return Collection<int, InvoiceLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(InvoiceLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $line->setInvoice($this);
            $this->lines->add($line);
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
