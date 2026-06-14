<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Repository\ReservationActionRepository;
use App\Timeline\ReservationActionExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron (á 15 min) — vyhodnotí naplánované akce, kterým nadešel čas.
 * V MVP řeší self-resolving připomínky; odeslání zpráv hostům čeká na roadmap.
 */
#[AsCommand(name: 'app:actions:run', description: 'Vyhodnotí naplánované akce rezervací, kterým nadešel čas.')]
class ActionsRunCommand extends Command
{
    public function __construct(
        private readonly ReservationActionRepository $actions,
        private readonly ReservationActionExecutor $executor,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $due = $this->actions->findDue(new \DateTimeImmutable());

        $io->writeln(sprintf('Naplánovaných akcí po termínu: <info>%d</info>', count($due)));

        $resolved = 0;
        foreach ($due as $action) {
            if ($this->executor->execute($action)) {
                $resolved++;
                $io->writeln(sprintf(
                    '  <fg=green>✓</> #%d %s → %s',
                    $action->getId(),
                    $action->getType()->label(),
                    (string) $action->getResult(),
                ));
            }
        }

        if ($resolved > 0) {
            $this->em->flush();
        }

        $io->success(sprintf('Hotovo. vyřešeno=%d, ponecháno=%d', $resolved, count($due) - $resolved));

        return Command::SUCCESS;
    }
}
