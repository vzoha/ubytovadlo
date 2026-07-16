<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Embeddable\ElectricityUsage;
use App\Entity\Embeddable\VatReverseCharge;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\PurposeOfStay;
use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use App\ValueObject\PhoneNumber;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: 'reservation')]
#[ORM\UniqueConstraint(name: 'uniq_channel_external_id', columns: ['channel', 'external_id'])]
#[ORM\UniqueConstraint(name: 'uniq_motopress_external_id', columns: ['motopress_external_id'])]
#[ORM\UniqueConstraint(name: 'uniq_reservation_checkin_token', columns: ['checkin_token'])]
#[ORM\Index(name: 'idx_status', columns: ['status'])]
#[ORM\Index(name: 'idx_check_in', columns: ['check_in'])]
#[ORM\Index(name: 'idx_ical_uid', columns: ['ical_uid'])]
class Reservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: Channel::class)]
    private Channel $channel;

    #[ORM\Column(length: 32, enumType: ReservationStatus::class)]
    private ReservationStatus $status = ReservationStatus::NEEDS_DETAILS;

    #[ORM\Column(length: 32, enumType: BillingMode::class, nullable: true)]
    private ?BillingMode $billingMode = null;

    /**
     * Source signal from MotoPress (bank/cash/manual). Auditní stopa, podle které
     * jsme určili billingMode — pro případ, že majitelka ručně změní mód.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $motopressPaymentGateway = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $motopressExternalId = null;

    /**
     * UID VEVENTu z OTA iCal feedu (Airbnb/Booking/eChalupy/CS chalupy). Stabilní
     * identita bloku obsazenosti napříč běhy — podle ní se rezervace dohledá,
     * aktualizuje a pozná se storno (blok zmizí z feedu). Jen u rezervací
     * založených/adoptovaných iCal importem.
     */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $icalUid = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $checkIn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkOut = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkInTime = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkOutTime = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $guestsAdult = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $guestsChild = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $guestsInfant = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $guestsSplitManually = false;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $priceTotal = null;

    #[ORM\Column(length: 3, options: ['default' => 'CZK'])]
    private string $priceCurrency = 'CZK';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $commissionAmount = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $commissionCurrency = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $netPayout = null;

    // Reálná výplata z Airbnb e-mailu "Poslali jsme ti výplatu …" (na rozdíl od
    // netPayout, který je predikce z potvrzení). payoutSentAt = den, kdy Airbnb
    // peníze odeslal = podklad pro datum úhrady faktury hostovi.
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $payoutAmount = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $payoutSentAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $payoutReference = null;

    // Reverse charge DPH (identifikovaná osoba § 6h ZDPH) — přepočet provize OTA
    // kurzem ČNB k DUZP; base × 21 % bez nároku na odpočet.
    #[ORM\Embedded(class: VatReverseCharge::class, columnPrefix: false)]
    private VatReverseCharge $vatReverseCharge;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $guestName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $guestEmail = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $guestPhone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $guestStreet = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $guestCity = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $guestZip = null;

    /** ISO 3166-1 alpha-2 ("CZ", "DE"). Z MotoPressu jako 2-pisemny kod. */
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $guestCountry = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $guestCompanyName = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $guestIco = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $guestDic = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $guestRegion = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * Marketing — odkud nás host zná (e-chalupy, FB, Google, návrat, doporučení, …).
     * Ortogonální ke `channel` (technický pipeline). Sync na to nesahá.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $acquisitionSource = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $hasPet = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $petsNote = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $needsBabyCot = false;

    // Elektřina — evidenční (hostům neúčtujeme, je v ceně). Plní ElectricityAllocator
    // z odečtů. measured = rezervace pokryta vlastními odečty před+po, allocated = rozpočet.
    #[ORM\Embedded(class: ElectricityUsage::class, columnPrefix: false)]
    private ElectricityUsage $electricity;

    // Ubyport — veřejný check-in link pro hosta + účel pobytu na rezervaci.
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $checkinToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkinCompletedAt = null;

    #[ORM\Column(length: 2, enumType: PurposeOfStay::class, options: ['default' => '10'])]
    private PurposeOfStay $ubyportPurposeOfStay = PurposeOfStay::TURISTIKA;

    // Ubyport — stav nahlášení per rezervace (UNL = vždy jedna rezervace).
    // exportedAt = stažen UNL; confirmedAt = nahrána doručenka (nebo ruční potvrzení).
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $ubyportExportedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $ubyportConfirmedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ubyportReceiptFilename = null;

    /** Počet přijatých / nepřijatých záznamů vyparsovaný z doručenky (kontrola). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $ubyportReceiptAccepted = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $ubyportReceiptRejected = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $bookedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Channel $channel, \DateTimeImmutable $checkIn)
    {
        $this->channel = $channel;
        $this->checkIn = $checkIn;
        $this->electricity = new ElectricityUsage();
        $this->vatReverseCharge = new VatReverseCharge();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChannel(): Channel
    {
        return $this->channel;
    }

    public function getStatus(): ReservationStatus
    {
        return $this->status;
    }

    public function getBillingMode(): ?BillingMode
    {
        return $this->billingMode;
    }

    public function setBillingMode(?BillingMode $billingMode): self
    {
        $this->billingMode = $billingMode;
        $this->touch();

        return $this;
    }

    public function getMotopressPaymentGateway(): ?string
    {
        return $this->motopressPaymentGateway;
    }

    public function setMotopressPaymentGateway(?string $gateway): self
    {
        $this->motopressPaymentGateway = $gateway;
        $this->touch();

        return $this;
    }

    public function setStatus(ReservationStatus $status): self
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): self
    {
        $this->externalId = $externalId;
        $this->touch();

        return $this;
    }

    public function getMotopressExternalId(): ?string
    {
        return $this->motopressExternalId;
    }

    public function setMotopressExternalId(?string $motopressExternalId): self
    {
        $this->motopressExternalId = $motopressExternalId;
        $this->touch();

        return $this;
    }

    /**
     * Variabilní symbol pro platbu zálohy hostem: MotoPress booking ID (web klasika),
     * jinak vlastní externí kód, jinak interní ID. Číselný tvar pro banku i QR Platbu;
     * párování příchozí platby dohledává rezervaci podle stejné hodnoty.
     */
    public function getPaymentVariableSymbol(): ?string
    {
        return $this->motopressExternalId ?? $this->externalId ?? ($this->id !== null ? (string) $this->id : null);
    }

    public function getIcalUid(): ?string
    {
        return $this->icalUid;
    }

    public function setIcalUid(?string $icalUid): self
    {
        $this->icalUid = $icalUid;
        $this->touch();

        return $this;
    }

    public function getCheckIn(): \DateTimeImmutable
    {
        return $this->checkIn;
    }

    public function setCheckIn(\DateTimeImmutable $checkIn): self
    {
        $this->checkIn = $checkIn;
        $this->touch();

        return $this;
    }

    public function getCheckOut(): ?\DateTimeImmutable
    {
        return $this->checkOut;
    }

    public function setCheckOut(?\DateTimeImmutable $checkOut): self
    {
        $this->checkOut = $checkOut;
        $this->touch();

        return $this;
    }

    public function getCheckInTime(): ?\DateTimeImmutable
    {
        return $this->checkInTime;
    }

    public function setCheckInTime(?\DateTimeImmutable $checkInTime): self
    {
        $this->checkInTime = $checkInTime;
        $this->touch();

        return $this;
    }

    public function getCheckOutTime(): ?\DateTimeImmutable
    {
        return $this->checkOutTime;
    }

    public function setCheckOutTime(?\DateTimeImmutable $checkOutTime): self
    {
        $this->checkOutTime = $checkOutTime;
        $this->touch();

        return $this;
    }

    public function getGuestsAdult(): int
    {
        return $this->guestsAdult;
    }

    public function setGuestsAdult(int $guestsAdult): self
    {
        $this->guestsAdult = $guestsAdult;
        $this->touch();

        return $this;
    }

    public function getGuestsChild(): int
    {
        return $this->guestsChild;
    }

    public function setGuestsChild(int $guestsChild): self
    {
        $this->guestsChild = $guestsChild;
        $this->touch();

        return $this;
    }

    public function getGuestsInfant(): int
    {
        return $this->guestsInfant;
    }

    public function setGuestsInfant(int $guestsInfant): self
    {
        $this->guestsInfant = $guestsInfant;
        $this->touch();

        return $this;
    }

    public function getGuestsTotal(): int
    {
        return $this->guestsAdult + $this->guestsChild + $this->guestsInfant;
    }

    public function isGuestsSplitManually(): bool
    {
        return $this->guestsSplitManually;
    }

    public function setGuestsSplitManually(bool $guestsSplitManually): self
    {
        $this->guestsSplitManually = $guestsSplitManually;
        $this->touch();

        return $this;
    }

    public function getPriceTotal(): ?string
    {
        return $this->priceTotal;
    }

    public function setPriceTotal(?string $priceTotal): self
    {
        $this->priceTotal = $priceTotal;
        $this->touch();

        return $this;
    }

    public function getPriceCurrency(): string
    {
        return $this->priceCurrency;
    }

    public function setPriceCurrency(string $priceCurrency): self
    {
        $this->priceCurrency = $priceCurrency;
        $this->touch();

        return $this;
    }

    public function getCommissionAmount(): ?string
    {
        return $this->commissionAmount;
    }

    public function setCommissionAmount(?string $commissionAmount): self
    {
        $this->commissionAmount = $commissionAmount;
        $this->touch();

        return $this;
    }

    public function getCommissionCurrency(): ?string
    {
        return $this->commissionCurrency;
    }

    public function setCommissionCurrency(?string $commissionCurrency): self
    {
        $this->commissionCurrency = $commissionCurrency;
        $this->touch();

        return $this;
    }

    public function getNetPayout(): ?string
    {
        return $this->netPayout;
    }

    public function setNetPayout(?string $netPayout): self
    {
        $this->netPayout = $netPayout;
        $this->touch();

        return $this;
    }

    public function getPayoutAmount(): ?string
    {
        return $this->payoutAmount;
    }

    public function setPayoutAmount(?string $payoutAmount): self
    {
        $this->payoutAmount = $payoutAmount;
        $this->touch();

        return $this;
    }

    public function getPayoutSentAt(): ?\DateTimeImmutable
    {
        return $this->payoutSentAt;
    }

    public function setPayoutSentAt(?\DateTimeImmutable $payoutSentAt): self
    {
        $this->payoutSentAt = $payoutSentAt;
        $this->touch();

        return $this;
    }

    public function getPayoutReference(): ?string
    {
        return $this->payoutReference;
    }

    public function setPayoutReference(?string $payoutReference): self
    {
        $this->payoutReference = $payoutReference;
        $this->touch();

        return $this;
    }

    public function getVatReverseCharge(): VatReverseCharge
    {
        return $this->vatReverseCharge;
    }

    public function setVatReverseCharge(VatReverseCharge $vatReverseCharge): self
    {
        $this->vatReverseCharge = $vatReverseCharge;
        $this->touch();

        return $this;
    }

    public function getGuestName(): ?string
    {
        return $this->guestName;
    }

    public function setGuestName(?string $guestName): self
    {
        $this->guestName = $guestName;
        $this->touch();

        return $this;
    }

    public function getGuestEmail(): ?string
    {
        return $this->guestEmail;
    }

    public function setGuestEmail(?string $guestEmail): self
    {
        $this->guestEmail = $guestEmail;
        $this->touch();

        return $this;
    }

    public function getGuestPhone(): ?string
    {
        return $this->guestPhone;
    }

    public function setGuestPhone(?string $guestPhone): self
    {
        $phone = PhoneNumber::tryFromString($guestPhone);
        if ($phone !== null) {
            $this->guestPhone = $phone->e164();
        } else {
            $trimmed = trim((string) $guestPhone);
            $this->guestPhone = $trimmed === '' ? null : $trimmed;
        }
        $this->touch();

        return $this;
    }

    public function getGuestStreet(): ?string
    {
        return $this->guestStreet;
    }

    public function setGuestStreet(?string $guestStreet): self
    {
        $this->guestStreet = $guestStreet;
        $this->touch();

        return $this;
    }

    public function getGuestCity(): ?string
    {
        return $this->guestCity;
    }

    public function setGuestCity(?string $guestCity): self
    {
        $this->guestCity = $guestCity;
        $this->touch();

        return $this;
    }

    public function getGuestZip(): ?string
    {
        return $this->guestZip;
    }

    public function setGuestZip(?string $guestZip): self
    {
        $this->guestZip = $guestZip;
        $this->touch();

        return $this;
    }

    public function getGuestCountry(): ?string
    {
        return $this->guestCountry;
    }

    public function setGuestCountry(?string $guestCountry): self
    {
        $normalized = $guestCountry !== null ? strtoupper(trim($guestCountry)) : null;
        $this->guestCountry = $normalized === '' ? null : $normalized;
        $this->touch();

        return $this;
    }

    public function hasGuestAddress(): bool
    {
        return $this->guestStreet !== null || $this->guestCity !== null || $this->guestZip !== null;
    }

    public function getGuestAddressFormatted(): ?string
    {
        if (!$this->hasGuestAddress()) {
            return null;
        }
        $cityZip = trim(($this->guestZip ?? '') . ' ' . ($this->guestCity ?? ''));
        $parts = array_filter([$this->guestStreet, $cityZip !== '' ? $cityZip : null], static fn (?string $p): bool => $p !== null && $p !== '');

        return implode(', ', $parts);
    }

    public function getGuestCompanyName(): ?string
    {
        return $this->guestCompanyName;
    }

    public function setGuestCompanyName(?string $guestCompanyName): self
    {
        $this->guestCompanyName = $guestCompanyName;
        $this->touch();

        return $this;
    }

    public function getGuestIco(): ?string
    {
        return $this->guestIco;
    }

    public function setGuestIco(?string $guestIco): self
    {
        $this->guestIco = $guestIco;
        $this->touch();

        return $this;
    }

    public function getGuestDic(): ?string
    {
        return $this->guestDic;
    }

    public function setGuestDic(?string $guestDic): self
    {
        $this->guestDic = $guestDic;
        $this->touch();

        return $this;
    }

    public function getGuestRegion(): ?string
    {
        return $this->guestRegion;
    }

    public function setGuestRegion(?string $guestRegion): self
    {
        $this->guestRegion = $guestRegion;
        $this->touch();

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        $this->touch();

        return $this;
    }

    public function getAcquisitionSource(): ?string
    {
        return $this->acquisitionSource;
    }

    public function setAcquisitionSource(?string $acquisitionSource): self
    {
        $value = $acquisitionSource !== null ? trim($acquisitionSource) : null;
        $this->acquisitionSource = $value === '' ? null : $value;
        $this->touch();

        return $this;
    }

    public function hasPet(): bool
    {
        return $this->hasPet;
    }

    public function setHasPet(bool $hasPet): self
    {
        $this->hasPet = $hasPet;
        $this->touch();

        return $this;
    }

    public function getPetsNote(): ?string
    {
        return $this->petsNote;
    }

    public function setPetsNote(?string $petsNote): self
    {
        $this->petsNote = $petsNote;
        $this->touch();

        return $this;
    }

    public function needsBabyCot(): bool
    {
        return $this->needsBabyCot;
    }

    public function setNeedsBabyCot(bool $needsBabyCot): self
    {
        $this->needsBabyCot = $needsBabyCot;
        $this->touch();

        return $this;
    }

    public function getElectricity(): ElectricityUsage
    {
        return $this->electricity;
    }

    public function setElectricity(ElectricityUsage $electricity): self
    {
        $this->electricity = $electricity;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getBookedAt(): ?\DateTimeImmutable
    {
        return $this->bookedAt;
    }

    public function setBookedAt(?\DateTimeImmutable $bookedAt): self
    {
        $this->bookedAt = $bookedAt;
        $this->touch();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCheckinToken(): ?string
    {
        return $this->checkinToken;
    }

    public function setCheckinToken(?string $checkinToken): self
    {
        $this->checkinToken = $checkinToken;
        $this->touch();

        return $this;
    }

    public function getCheckinCompletedAt(): ?\DateTimeImmutable
    {
        return $this->checkinCompletedAt;
    }

    public function markCheckinCompleted(): self
    {
        $this->checkinCompletedAt = new \DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function resetCheckin(): self
    {
        $this->checkinCompletedAt = null;
        $this->touch();

        return $this;
    }

    public function getUbyportPurposeOfStay(): PurposeOfStay
    {
        return $this->ubyportPurposeOfStay;
    }

    public function setUbyportPurposeOfStay(PurposeOfStay $purpose): self
    {
        $this->ubyportPurposeOfStay = $purpose;
        $this->touch();

        return $this;
    }

    public function getUbyportExportedAt(): ?\DateTimeImmutable
    {
        return $this->ubyportExportedAt;
    }

    public function markUbyportExported(\DateTimeImmutable $at): self
    {
        $this->ubyportExportedAt = $at;
        $this->touch();

        return $this;
    }

    public function getUbyportConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->ubyportConfirmedAt;
    }

    public function getUbyportReceiptFilename(): ?string
    {
        return $this->ubyportReceiptFilename;
    }

    public function getUbyportReceiptAccepted(): ?int
    {
        return $this->ubyportReceiptAccepted;
    }

    public function getUbyportReceiptRejected(): ?int
    {
        return $this->ubyportReceiptRejected;
    }

    /**
     * Potvrdí nahlášení do Ubyportu. S doručenkou (filename + počty), nebo
     * ručně (vše null). Pokud ještě nebyl označen export, dorovná i ten —
     * nahraná doručenka implikuje, že UNL šel ven.
     */
    public function confirmUbyportReported(
        \DateTimeImmutable $at,
        ?string $filename = null,
        ?int $accepted = null,
        ?int $rejected = null,
    ): self {
        $this->ubyportConfirmedAt = $at;
        $this->ubyportReceiptFilename = $filename;
        $this->ubyportReceiptAccepted = $accepted;
        $this->ubyportReceiptRejected = $rejected;
        $this->ubyportExportedAt ??= $at;
        $this->touch();

        return $this;
    }

    /** Vrátí rezervaci zpět do fronty k nahlášení (smaže export i doručenku). */
    public function resetUbyport(): self
    {
        $this->ubyportExportedAt = null;
        $this->ubyportConfirmedAt = null;
        $this->ubyportReceiptFilename = null;
        $this->ubyportReceiptAccepted = null;
        $this->ubyportReceiptRejected = null;
        $this->touch();

        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
