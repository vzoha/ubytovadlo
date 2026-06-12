<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Service\Electricity\ElectricityAllocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:electricity:rebalance', description: 'Rozpočítat spotřebu elektřiny mezi rezervace podle odečtů a sezónního profilu.')]
class ElectricityRebalanceCommand extends Command
{
    public function __construct(
        private readonly ElectricityAllocator $allocator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $stats = $this->allocator->rebalanceAll();

        $io->success(sprintf(
            'Zpracováno %d intervalů, alokováno %d rezervacím, přeskočeno %d s vlastními odečty.',
            $stats->intervals,
            $stats->reservations,
            $stats->skippedMeasured,
        ));

        return Command::SUCCESS;
    }
}
