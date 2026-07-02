<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Cashflow\IncomeUpserter;
use App\Repository\ReservationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Přepočítá reálně přijaté platby (ReservationReceipt) u všech rezervací z jejich
 * aktuálních faktur/plateb/výplat. Idempotentní; ručně přepsané výplaty
 * (manuallyOverridden) nechává být. Použití: hromadné dorovnání po nasazení
 * cashflow modulu nebo po změně příjmové logiky.
 */
#[AsCommand(name: 'app:cashflow:recompute-incomes', description: 'Přepočítat přijaté platby (ReservationReceipt) u všech rezervací.')]
class RecomputeIncomeCommand extends Command
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly IncomeUpserter $incomeUpserter,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reservations = $this->reservations->findAll();

        foreach ($reservations as $reservation) {
            $this->incomeUpserter->recompute($reservation);
        }

        $io->success(sprintf('Přepočítáno %d rezervací.', \count($reservations)));

        return Command::SUCCESS;
    }
}
