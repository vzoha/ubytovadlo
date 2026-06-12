<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Repository\ReservationRepository;
use App\Vat\VatCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:vat:recalculate', description: 'Přepočítat DPH (reverse charge) na rezervacích s vyplněnou provizí.')]
class VatRecalculateCommand extends Command
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly VatCalculator $calculator,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('month', null, InputOption::VALUE_REQUIRED, 'Omezit na rezervace s odjezdem v měsíci, např. 2026-04');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $month = $input->getOption('month');
        if ($month !== null) {
            if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
                $io->error('--month musí být ve formátu YYYY-MM');

                return Command::FAILURE;
            }
            $reservations = $this->reservations->findCommissionableByCheckoutMonth((int) $m[1], (int) $m[2]);
        } else {
            $reservations = $this->reservations->findAllWithCommission();
        }

        $changed = 0;
        $skipped = 0;
        foreach ($reservations as $r) {
            if ($this->calculator->recalculate($r)) {
                $io->writeln(sprintf(
                    '  ✓ #%d %s %s → base %s Kč, DPH %s Kč (kurz %s k %s)',
                    $r->getId(),
                    $r->getChannel()->value,
                    $r->getCheckOut()?->format('Y-m-d'),
                    $r->getVatBaseCzk(),
                    $r->getVatAmountCzk(),
                    $r->getVatCnbRate() ?? '—',
                    $r->getVatCnbRateDate()?->format('Y-m-d') ?? '—',
                ));
                $changed++;
            } else {
                $skipped++;
            }
        }

        $this->em->flush();
        $io->success(sprintf('Hotovo. přepočítáno=%d přeskočeno=%d', $changed, $skipped));

        return Command::SUCCESS;
    }
}
