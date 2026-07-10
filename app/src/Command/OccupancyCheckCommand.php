<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Occupancy\OccupancyConflictFinder;
use App\Repository\ReservationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sanity kontrola obsazenosti: najde překrývající se aktivní rezervace (dvojí
 * prodej termínu), typicky když sync z více kanálů založí kolidující bloky.
 * Read-only — nic nemění, jen hlásí. Vhodné z cronu i ručně.
 */
#[AsCommand(name: 'app:occupancy:check', description: 'Najde překrývající se rezervace (dvojí obsazení termínu).')]
final class OccupancyCheckCommand extends Command
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly OccupancyConflictFinder $finder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $active = $this->reservations->findActiveForOccupancy(new \DateTimeImmutable('today'));
        $conflicts = $this->finder->find($active);

        if ($conflicts === []) {
            $io->success(sprintf('Žádné překryvy — %d aktivních rezervací v pořádku.', \count($active)));

            return Command::SUCCESS;
        }

        $io->warning(sprintf('Nalezeno %d překrývajících se termínů:', \count($conflicts)));
        $io->table(
            ['Termín překryvu', 'Nocí', 'Rezervace A', 'Rezervace B'],
            array_map(static fn ($c): array => [
                $c->from->format('j.n.Y') . ' – ' . $c->to->format('j.n.Y'),
                $c->nights(),
                self::label($c->a),
                self::label($c->b),
            ], $conflicts),
        );

        return Command::FAILURE;
    }

    private static function label(\App\Entity\Reservation $r): string
    {
        return sprintf(
            '#%d %s (%s%s)',
            $r->getId() ?? 0,
            $r->getGuestName() ?? '—',
            $r->getChannel()->label(),
            $r->getCheckOut() !== null
                ? ', ' . $r->getCheckIn()->format('j.n.') . '–' . $r->getCheckOut()->format('j.n.')
                : '',
        );
    }
}
