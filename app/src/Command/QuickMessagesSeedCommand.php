<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Mail\QuickMessageSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:quick-messages:seed', description: 'Naplní prázdný seznam rychlých zpráv připravenou sadou textů.')]
class QuickMessagesSeedCommand extends Command
{
    public function __construct(
        private readonly QuickMessageSeeder $seeder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = $this->seeder->seedIfEmpty();

        if ($created === 0) {
            $io->note('Seznam rychlých zpráv už nějaké obsahuje — beze změny.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Založeno %d rychlých zpráv.', $created));

        return Command::SUCCESS;
    }
}
