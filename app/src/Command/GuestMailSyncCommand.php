<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Email\GuestInbox\GuestInboxException;
use App\GuestMail\GuestMailSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:guest-mail:sync', description: 'Uloží novou korespondenci ze schránky hostů k hostům a rezervacím.')]
class GuestMailSyncCommand extends Command
{
    public function __construct(
        private readonly GuestMailSync $sync,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->sync->isConfigured()) {
            $io->warning('Schránka hostů nemá vyplněné přístupy (IMAP) — přeskočeno.');

            return Command::SUCCESS;
        }

        try {
            $result = $this->sync->sync();
        } catch (GuestInboxException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($result->warnings as $warning) {
            $io->warning($warning);
        }
        $io->success(sprintf('Hotovo. doručené=%d odeslané=%d', $result->inbound, $result->outbound));

        return Command::SUCCESS;
    }
}
