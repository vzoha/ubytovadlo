<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Customer\CustomerLinker;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron (součást actions-plan) — přiřadí zákazníka rezervacím, které ho nemají,
 * ač je u nich koho poznat (jméno, e-mail, telefon): typicky pobyty uložené
 * před zavedením zákazníků. Idempotentní.
 */
#[AsCommand(name: 'app:customers:link', description: 'Přiřadí zákazníka rezervacím, které ho nemají (vracející se hosté).')]
class CustomersLinkCommand extends Command
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly CustomerLinker $linker,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $candidates = $this->reservations->findWithoutCustomer();

        $linked = 0;
        foreach ($candidates as $reservation) {
            if ($this->linker->link($reservation) !== null) {
                $linked++;
            }
        }

        if ($linked > 0) {
            $this->em->flush();
        }

        $io->success(sprintf('Hotovo. kandidátů=%d, spárováno=%d', count($candidates), $linked));

        return Command::SUCCESS;
    }
}
