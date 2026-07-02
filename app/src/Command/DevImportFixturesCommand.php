<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Email\EmailDispatcher;
use App\Email\EmlReader;
use App\Notification\OwnerNotificationSettingsProvider;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:dev:import-fixtures', description: 'Replay sample .eml fixtures through the dispatcher (dev only).')]
class DevImportFixturesCommand extends Command
{
    public function __construct(
        private readonly EmailDispatcher $dispatcher,
        private readonly EmlReader $reader,
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Neutrální příjemce notifikací, ať replay naplní i frontu notifikací
        // a nastavovací stránka má v demu co ukázat.
        $this->settings->set(OwnerNotificationSettingsProvider::RECIPIENT, 'majitel@example.com', 'Demo příjemce notifikací.');
        $this->em->flush();

        $files = glob($this->projectDir . '/tests/Fixtures/{Airbnb,Booking}/*.eml', GLOB_BRACE) ?: [];
        if ($files === []) {
            $io->warning('No fixture .eml files found.');

            return Command::SUCCESS;
        }

        foreach ($files as $file) {
            $email = $this->reader->fromFile($file);
            $log = $this->dispatcher->dispatch($email);
            $io->writeln(sprintf('  [%s] %s', $log->getStatus()->value, basename($file)));
        }

        $io->success(sprintf('Imported %d fixtures.', count($files)));

        return Command::SUCCESS;
    }
}
