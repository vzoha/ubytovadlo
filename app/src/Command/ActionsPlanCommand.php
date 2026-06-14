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
use App\Timeline\ReservationActionPlanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron (á 15 min) — idempotentně doplní automatické akce na časovou osu u
 * potvrzených rezervací, jejichž pobyt ještě neskončil. Pokrývá i rezervace
 * potvrzené přes MotoPress sync, aniž by sync musel plánovač volat sám.
 */
#[AsCommand(name: 'app:actions:plan', description: 'Doplní automatické akce na časovou osu nadcházejících rezervací.')]
class ActionsPlanCommand extends Command
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ReservationActionPlanner $planner,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reservations = $this->reservations->findForActionPlanning(new \DateTimeImmutable('today'));

        $added = 0;
        foreach ($reservations as $reservation) {
            $added += $this->planner->planFor($reservation);
        }

        if ($added > 0) {
            $this->em->flush();
        }

        $io->success(sprintf('Hotovo. zkontrolováno rezervací=%d, nově naplánováno akcí=%d', count($reservations), $added));

        return Command::SUCCESS;
    }
}
