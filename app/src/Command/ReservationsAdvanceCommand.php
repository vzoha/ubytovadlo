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
use App\Reservation\ReservationLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron (á 15 min) — posune stav rezervací podle kalendáře: v den příjezdu na
 * „probíhá", den po odjezdu na „dokončeno". Idempotentní, mění jen rezervace,
 * jejichž stav neodpovídá datům.
 */
#[AsCommand(name: 'app:reservations:advance', description: 'Posune stav rezervací podle kalendáře (probíhá / dokončeno).')]
class ReservationsAdvanceCommand extends Command
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ReservationLifecycle $lifecycle,
        private readonly ClockInterface $clock,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $today = $this->clock->now()->setTime(0, 0);
        $reservations = $this->reservations->findForStatusAdvance($today);

        $moved = 0;
        foreach ($reservations as $reservation) {
            $status = $this->lifecycle->derive($reservation);
            if ($status === $reservation->getStatus()) {
                continue;
            }
            $reservation->setStatus($status);
            $moved++;
        }

        if ($moved > 0) {
            $this->em->flush();
        }

        $io->success(sprintf('Hotovo. zkontrolováno rezervací=%d, posunuto stavů=%d', count($reservations), $moved));

        return Command::SUCCESS;
    }
}
