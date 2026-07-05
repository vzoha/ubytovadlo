<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Connector\ConnectorManager;
use App\Enum\ConnectorStatus;
use App\Enum\ConnectorType;
use App\Ical\Import\IcalFeedException;
use App\Ical\Import\IcalImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ical:sync', description: 'Importuje obsazenost z OTA iCal feedů (Booking, Airbnb, eChalupy, CS chalupy).')]
class IcalSyncCommand extends Command
{
    public function __construct(
        private readonly IcalImporter $importer,
        private readonly ConnectorManager $connectors,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nic neukládá, jen vypíše, co by import udělal.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $rows = [];
        $failures = 0;

        foreach (ConnectorType::icalConnectors() as $type) {
            if (!$this->connectors->isEnabled($type)) {
                $rows[] = [$type->label(), 'vypnuto', '–', '–', '–', '–'];
                continue;
            }
            if ($this->connectors->getFeedUrl($type) === null) {
                $rows[] = [$type->label(), 'bez feedu', '–', '–', '–', '–'];
                continue;
            }

            try {
                $result = $this->importer->import($type, $dryRun);
            } catch (IcalFeedException $e) {
                if (!$dryRun) {
                    $this->connectors->recordRun($type, ConnectorStatus::ERROR, $e->getMessage());
                }
                $rows[] = [$type->label(), 'chyba: ' . $e->getMessage(), '–', '–', '–', '–'];
                $failures++;
                continue;
            }

            // Úspěšný běh = aktivita (feed odpověděl), i bez nové/změněné rezervace —
            // jinak by konektor v klidu falešně spadl do „dlouho bez dat".
            if (!$dryRun) {
                $this->connectors->recordActivity($type);
                $this->connectors->recordRun($type, ConnectorStatus::OK);
            }

            $rows[] = [
                $type->label(),
                'ok (' . $result->total . ' událostí)',
                (string) $result->created,
                (string) $result->updated,
                (string) $result->unchanged,
                (string) $result->cancelled,
            ];
        }

        $io->table(['Feed', 'Stav', 'Nové', 'Upraveno', 'Beze změny', 'Storna'], $rows);

        if ($failures > 0) {
            $io->warning(sprintf('%s — %d feed(ů) selhalo.', $dryRun ? 'DRY RUN' : 'iCal import hotov', $failures));

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'DRY RUN hotov.' : 'iCal import hotov.');

        return Command::SUCCESS;
    }
}
