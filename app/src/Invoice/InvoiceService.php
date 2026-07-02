<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

use App\Cashflow\IncomeUpserter;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Reservation;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Repository\InvoiceRepository;
use App\Vat\CnbExchangeRateClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Orchestrace vystavení faktur. Tři vstupní body podle toku:
 *  - issueDeposit(): zálohová 1000 Kč pro Web klasiku (gateway bank).
 *  - issueFinal(Reservation, Invoice $deposit): konečná s odpočtem zálohy.
 *  - issueFull(): jedna faktura na celou částku (FKSP, admin, Airbnb, Booking).
 *
 * Pro Booking přepočítává EUR → CZK kurzem ČNB ke dni vystavení.
 */
class InvoiceService
{
    public const PAYMENT_BANK = 'převodem';
    public const PAYMENT_CASH = 'hotově';

    private const DUE_DAYS_DEFAULT = 2;
    private const DUE_DAYS_FKSP = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceRepository $invoiceRepo,
        private readonly InvoiceNumberAllocator $allocator,
        private readonly InvoicePdfRenderer $pdfRenderer,
        private readonly SpaydGenerator $spayd,
        private readonly CnbExchangeRateClient $cnb,
        private readonly IssuerProfileProvider $issuerProvider,
        private readonly IncomeUpserter $incomeUpserter,
        private readonly string $invoiceDepositAmount,
    ) {
    }

    public function issueDeposit(Reservation $reservation, ?\DateTimeImmutable $issuedAt = null): Invoice
    {
        $this->assertHasCustomer($reservation);
        $this->assertNotWaived($reservation);
        $this->assertNotYetIssued($reservation, InvoiceType::DEPOSIT);
        $issuedAt ??= new \DateTimeImmutable('today');

        $invoice = $this->buildInvoice($reservation, InvoiceType::DEPOSIT, $issuedAt, $issuedAt->modify('+2 days'));
        $invoice->setTotalAmount($this->invoiceDepositAmount);
        $line = new InvoiceLine('Záloha na ubytovací služby', $this->invoiceDepositAmount);
        $invoice->addLine($line);

        $this->fillBankPayment($invoice);
        $this->persist($invoice);

        return $invoice;
    }

    public function issueFinal(Reservation $reservation, Invoice $deposit, ?\DateTimeImmutable $issuedAt = null): Invoice
    {
        $this->assertHasCustomer($reservation);
        $this->assertNotWaived($reservation);
        $this->assertNotYetIssued($reservation, InvoiceType::FINAL);
        if ($deposit->getType() !== InvoiceType::DEPOSIT) {
            throw new \InvalidArgumentException('Parent invoice must be a DEPOSIT.');
        }
        if ($reservation->getPriceTotal() === null) {
            throw new \LogicException('Reservation nemá priceTotal — nelze vystavit doplatek.');
        }
        $issuedAt ??= new \DateTimeImmutable('today');

        $invoice = $this->buildInvoice($reservation, InvoiceType::FINAL, $issuedAt, $issuedAt->modify('+' . self::DUE_DAYS_DEFAULT . ' days'));
        $invoice->setParentInvoice($deposit);

        $total = bcsub($this->resolveTotalCzk($reservation, $invoice, $issuedAt), $deposit->getTotalAmount(), 2);
        $invoice->setTotalAmount($total);
        $invoice->addLine(new InvoiceLine('Ubytovací služby', $reservation->getPriceTotal()));
        $invoice->addLine(new InvoiceLine('Odpočet zálohy (faktura ' . $deposit->getNumber() . ')', '-' . $deposit->getTotalAmount()));

        $this->fillBankPayment($invoice);
        $this->persist($invoice);

        return $invoice;
    }

    public function issueFull(Reservation $reservation, ?\DateTimeImmutable $issuedAt = null): Invoice
    {
        $this->assertHasCustomer($reservation);
        $this->assertNotWaived($reservation);
        $this->assertNotYetIssued($reservation, InvoiceType::FULL);
        if ($reservation->getPriceTotal() === null) {
            throw new \LogicException('Reservation nemá priceTotal — nelze vystavit fakturu.');
        }
        $issuedAt ??= new \DateTimeImmutable('today');

        $dueDays = $reservation->getBillingMode() === BillingMode::FKSP ? self::DUE_DAYS_FKSP : self::DUE_DAYS_DEFAULT;
        $invoice = $this->buildInvoice($reservation, InvoiceType::FULL, $issuedAt, $issuedAt->modify('+' . $dueDays . ' days'));
        $totalCzk = $this->resolveTotalCzk($reservation, $invoice, $issuedAt);
        $invoice->setTotalAmount($totalCzk);
        $invoice->addLine(new InvoiceLine('Ubytovací služby', $totalCzk));

        if ($this->isOtaIntermediated($reservation)) {
            $invoice->setPaymentMethod('převodem – zprostředkovatel');
            $invoice->setBankAccount(null);

            // U Airbnb host platí zprostředkovateli předem; reálné peníze nám
            // dorazí výplatou. Pokud payout e-mail už dorazil (payoutSentAt),
            // vystavujeme fakturu rovnou jako uhrazenou ke dni odeslání výplaty.
            if ($reservation->getPayoutSentAt() !== null) {
                $invoice->setPaidAt($reservation->getPayoutSentAt());
            }
        } else {
            $this->fillBankPayment($invoice);
        }

        $this->persist($invoice);

        return $invoice;
    }

    public function markPaid(Invoice $invoice, ?\DateTimeImmutable $paidAt = null): void
    {
        $invoice->setPaidAt($paidAt ?? new \DateTimeImmutable('today'));
        $path = $this->pdfRenderer->renderToFile($invoice);
        $invoice->setPdfPath($path);
        $this->em->flush();

        // Úhrada faktury může změnit reálný příjem rezervace (u přímé objednávky
        // je to reálný příjem, u OTA aspoň zpřesní odhad).
        $this->incomeUpserter->recompute($invoice->getReservation());
    }

    public function regeneratePdf(Invoice $invoice): void
    {
        $path = $this->pdfRenderer->renderToFile($invoice);
        $invoice->setPdfPath($path);
        $this->em->flush();
    }

    private function buildInvoice(
        Reservation $reservation,
        InvoiceType $type,
        \DateTimeImmutable $issuedAt,
        \DateTimeImmutable $dueAt,
    ): Invoice {
        $number = $this->allocator->allocate($issuedAt);
        $invoice = new Invoice($number->formatted(), $number->year, $type, $reservation, $issuedAt, $dueAt);
        $invoice->setVariableSymbol($number->formatted());
        $invoice->setCurrency('CZK');
        $this->copyCustomerSnapshot($reservation, $invoice);

        return $invoice;
    }

    private function copyCustomerSnapshot(Reservation $reservation, Invoice $invoice): void
    {
        $invoice->setCustomerName($reservation->getGuestName() ?? '');
        $invoice->setCustomerStreet($reservation->getGuestStreet());
        $invoice->setCustomerCity($reservation->getGuestCity());
        $invoice->setCustomerZip($reservation->getGuestZip());
        $invoice->setCustomerCountry($this->countryLabel($reservation->getGuestCountry()));
        $invoice->setCustomerCompanyName($reservation->getGuestCompanyName());
        $invoice->setCustomerIco($reservation->getGuestIco());
        $invoice->setCustomerDic($reservation->getGuestDic());
    }

    /**
     * Pro Booking v EUR přepočítá ČNB kurzem ke dni vystavení a uloží originalAmount + rate.
     */
    private function resolveTotalCzk(Reservation $reservation, Invoice $invoice, \DateTimeImmutable $issuedAt): string
    {
        $amount = $reservation->getPriceTotal() ?? '0';
        $currency = $reservation->getPriceCurrency();

        if ($currency === 'CZK') {
            return number_format((float) $amount, 2, '.', '');
        }

        $rate = $this->cnb->getRate($currency, $issuedAt);
        $totalCzk = bcmul($amount, number_format($rate->rate, 8, '.', ''), 2);

        $invoice->setOriginalAmount($amount);
        $invoice->setOriginalCurrency($currency);
        $invoice->setExchangeRate(number_format($rate->rate, 8, '.', ''));
        $invoice->setExchangeRateDate($rate->validFor);

        return $totalCzk;
    }

    /**
     * Přepne způsob platby vystavené faktury (typicky doplatek hrazený hotově na místě).
     * Hotovost odstraní z faktury číslo účtu i QR; převod je doplní zpět.
     */
    public function changePaymentMethod(Invoice $invoice, string $method): void
    {
        match ($method) {
            self::PAYMENT_CASH => $invoice
                ->setPaymentMethod(self::PAYMENT_CASH)
                ->setBankAccount(null)
                ->setQrPayload(null),
            self::PAYMENT_BANK => $this->fillBankPayment($invoice),
            default => throw new \InvalidArgumentException(sprintf('Neznámý způsob platby "%s".', $method)),
        };
    }

    private function fillBankPayment(Invoice $invoice): void
    {
        $invoice->setPaymentMethod(self::PAYMENT_BANK);
        $invoice->setBankAccount($this->issuerProvider->current()->bankAccount);
        $this->refreshBankQr($invoice);
    }

    public function refreshBankQr(Invoice $invoice): void
    {
        $invoice->setQrPayload($this->spayd->generate(
            $this->issuerProvider->current()->bankAccountIban,
            $invoice->getTotalAmount(),
            $invoice->getCurrency(),
            $invoice->getVariableSymbol(),
            'Faktura ' . $invoice->getNumber(),
            $invoice->getDueAt(),
        ));
    }

    private function isOtaIntermediated(Reservation $reservation): bool
    {
        return in_array($reservation->getChannel(), [Channel::BOOKING, Channel::AIRBNB], true)
            || in_array($reservation->getBillingMode(), [BillingMode::BOOKING_COM, BillingMode::AIRBNB], true);
    }

    private function assertHasCustomer(Reservation $reservation): void
    {
        if (($reservation->getGuestName() ?? '') === '') {
            throw new \LogicException('Reservation nemá guestName — nelze vystavit fakturu.');
        }
    }

    private function assertNotWaived(Reservation $reservation): void
    {
        $mode = $reservation->getBillingMode();
        if ($mode !== null && !$mode->isInvoiced()) {
            throw new \LogicException('Reservation má billing mode "Bez fakturace" — fakturu nelze vystavit.');
        }
    }

    private function assertNotYetIssued(Reservation $reservation, InvoiceType $type): void
    {
        $existing = $this->invoiceRepo->findFirstByReservationAndType($reservation, $type);
        if ($existing !== null) {
            throw new \LogicException(sprintf('Faktura typu %s pro tuto rezervaci už existuje (%s).', $type->value, $existing->getNumber()));
        }
    }

    /**
     * ISO 3166-1 alpha-2 → český název. CZ vrací null, ať se na fakturách hostů z ČR
     * země netiskne (zbytečný šum). Neznámý kód propustíme tak jak je.
     */
    private function countryLabel(?string $iso): ?string
    {
        if ($iso === null) {
            return null;
        }
        $code = strtoupper($iso);
        if ($code === 'CZ') {
            return null;
        }

        return match ($code) {
            'DE' => 'Německo',
            'SK' => 'Slovensko',
            'AT' => 'Rakousko',
            'PL' => 'Polsko',
            'HU' => 'Maďarsko',
            'NL' => 'Nizozemsko',
            'BE' => 'Belgie',
            'FR' => 'Francie',
            'IT' => 'Itálie',
            'ES' => 'Španělsko',
            'GB' => 'Velká Británie',
            'IE' => 'Irsko',
            'DK' => 'Dánsko',
            'SE' => 'Švédsko',
            'NO' => 'Norsko',
            'FI' => 'Finsko',
            'CH' => 'Švýcarsko',
            'US' => 'USA',
            default => $code,
        };
    }

    private function persist(Invoice $invoice): void
    {
        foreach ($invoice->getLines() as $i => $line) {
            $line->setPosition($i);
        }
        // PDF až po flush: orphan PDF na disku je menší zlo než nezpersistovaná faktura s neexistujícím PDF.
        $this->em->persist($invoice);
        $this->em->flush();

        $path = $this->pdfRenderer->renderToFile($invoice);
        $invoice->setPdfPath($path);
        $this->em->flush();
    }
}
