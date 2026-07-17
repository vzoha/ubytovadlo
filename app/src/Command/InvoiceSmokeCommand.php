<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\Embeddable\Address;
use App\Entity\Embeddable\BillingIdentity;
use App\Entity\Reservation;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Invoice\InvoiceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:invoice:smoke', description: 'Smoke test fakturace: vystaví zálohu, doplatek a fakturu na celou částku pro tři ad-hoc rezervace.')]
class InvoiceSmokeCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceService $invoices,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reservation = $this->makeReservation('Smoke Klasika', Channel::WEB, BillingMode::STANDARD_WITH_DEPOSIT, '12500.00', 'CZK');
        $deposit = $this->invoices->issueDeposit($reservation, new \DateTimeImmutable('2026-05-12'));
        $output->writeln('Záloha: ' . $deposit->getNumber() . ' → ' . $deposit->getPdfPath());

        $final = $this->invoices->issueFinal($reservation, $deposit, new \DateTimeImmutable('2026-05-20'));
        $output->writeln('Doplatek: ' . $final->getNumber() . ' → ' . $final->getPdfPath());

        $fksp = $this->makeReservation('Smoke FKSP s.r.o.', Channel::WEB, BillingMode::FKSP, '8400.00', 'CZK');
        $fksp->setGuestBilling(new BillingIdentity('FKSP s.r.o.', '12345678'));
        $fkspInvoice = $this->invoices->issueFull($fksp, new \DateTimeImmutable('2026-05-12'));
        $output->writeln('FKSP: ' . $fkspInvoice->getNumber() . ' → ' . $fkspInvoice->getPdfPath());

        $booking = $this->makeReservation('Smoke Booking DE', Channel::BOOKING, BillingMode::BOOKING_COM, '420.00', 'EUR');
        $bookingInvoice = $this->invoices->issueFull($booking, new \DateTimeImmutable('2026-05-12'));
        $output->writeln('Booking EUR: ' . $bookingInvoice->getNumber() . ' (' . $bookingInvoice->getTotalAmount() . ' CZK z ' . $bookingInvoice->getOriginalAmount() . ' ' . $bookingInvoice->getOriginalCurrency() . ' kurzem ' . $bookingInvoice->getExchangeRate() . ')');

        return Command::SUCCESS;
    }

    private function makeReservation(string $name, Channel $channel, BillingMode $mode, string $total, string $currency): Reservation
    {
        $r = new Reservation($channel, new \DateTimeImmutable('2026-06-01'));
        $r->setCheckOut(new \DateTimeImmutable('2026-06-05'));
        $r->setGuestName($name);
        $r->setGuestAddress(new Address('Testovací 42', 'Praha', '11000'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setBillingMode($mode);
        $r->setPriceTotal($total);
        $r->setPriceCurrency($currency);
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }
}
