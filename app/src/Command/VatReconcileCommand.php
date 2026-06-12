<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Channel;
use App\Repository\BookingMonthlyInvoiceRepository;
use App\Repository\ReservationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Porovná sumu provizí z rezervací s odjezdem v daném měsíci proti Booking
 * měsíční faktuře pro stejné období. Pokud nesedí, vyhodí varování — typicky
 * to znamená chybějící rezervaci, nezahrnutý payment fee, nebo storno.
 */
#[AsCommand(name: 'app:vat:reconcile', description: 'Porovnat provize z rezervací proti Booking měsíční faktuře.')]
class VatReconcileCommand extends Command
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly BookingMonthlyInvoiceRepository $invoices,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('month', null, InputOption::VALUE_REQUIRED, 'Měsíc ve formátu YYYY-MM', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $month = $input->getOption('month');
        if ($month === null || !preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
            $io->error('Použij --month=YYYY-MM (např. 2026-04).');

            return Command::FAILURE;
        }
        $year = (int) $m[1];
        $mo = (int) $m[2];

        $io->section(sprintf('Reconcile za %04d-%02d', $year, $mo));

        $this->reconcileBooking($io, $year, $mo);
        $this->summarizeAirbnb($io, $year, $mo);

        return Command::SUCCESS;
    }

    private function reconcileBooking(SymfonyStyle $io, int $year, int $month): void
    {
        $bookingReservations = $this->reservations->findCommissionableByCheckoutMonth($year, $month, Channel::BOOKING);
        $invoice = $this->invoices->findByPeriodMonth($year, $month);

        if ($bookingReservations === [] && $invoice === null) {
            $io->writeln('Booking: žádné rezervace ani faktura.');

            return;
        }

        $reservationSum = 0.0;
        $rows = [];
        foreach ($bookingReservations as $r) {
            $reservationSum += (float) $r->getCommissionAmount();
            $rows[] = [$r->getId(), $r->getCheckOut()?->format('Y-m-d'), $r->getGuestName() ?? '—', $r->getCommissionAmount() . ' ' . ($r->getCommissionCurrency() ?? 'CZK')];
        }
        if ($rows !== []) {
            $io->table(['#', 'odjezd', 'host', 'provize'], $rows);
        }

        if ($invoice === null) {
            $io->warning(sprintf('Booking rezervace s odjezdem v %04d-%02d sčítají %.2f, ale měsíční faktura ještě nepřišla.', $year, $month, $reservationSum));

            return;
        }

        $io->writeln(sprintf(
            'Booking faktura #%s (období %s – %s): provize %.2f %s, payment fee %.2f, celkem %.2f %s',
            $invoice->getInvoiceNumber(),
            $invoice->getPeriodFrom()->format('Y-m-d'),
            $invoice->getPeriodTo()->format('Y-m-d'),
            (float) $invoice->getCommission(),
            $invoice->getCurrency(),
            (float) $invoice->getPaymentFee(),
            (float) $invoice->getTotalPayable(),
            $invoice->getCurrency(),
        ));

        $invoiceTotal = (float) $invoice->getTotalPayable();
        $diff = round($reservationSum - $invoiceTotal, 2);
        if (abs($diff) < 0.01) {
            $io->success(sprintf('Souhlasí na halíř: rezervace %.2f vs. faktura %.2f.', $reservationSum, $invoiceTotal));
        } else {
            $io->warning(sprintf('Rozdíl: rezervace %.2f vs. faktura %.2f (Δ %.2f %s).', $reservationSum, $invoiceTotal, $diff, $invoice->getCurrency()));
        }
    }

    private function summarizeAirbnb(SymfonyStyle $io, int $year, int $month): void
    {
        $airbnb = $this->reservations->findCommissionableByCheckoutMonth($year, $month, Channel::AIRBNB);
        if ($airbnb === []) {
            $io->writeln('Airbnb: žádné rezervace s odjezdem v měsíci.');

            return;
        }

        $sum = 0.0;
        $rows = [];
        foreach ($airbnb as $r) {
            $sum += (float) $r->getCommissionAmount();
            $rows[] = [$r->getId(), $r->getCheckOut()?->format('Y-m-d'), $r->getGuestName() ?? '—', $r->getCommissionAmount() . ' ' . ($r->getCommissionCurrency() ?? 'CZK')];
        }
        $io->section('Airbnb');
        $io->table(['#', 'odjezd', 'host', 'provize'], $rows);
        $io->writeln(sprintf('Součet provizí: %.2f Kč (Airbnb neposílá souhrnnou fakturu — porovnej s VAT receipty v appce).', $sum));
    }
}
