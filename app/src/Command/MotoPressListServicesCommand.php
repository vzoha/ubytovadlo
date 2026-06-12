<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\MotoPress\MotoPressApiException;
use App\MotoPress\MotoPressClient;
use App\Reservation\GuestRequestKeywords;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:motopress:list-services', description: 'Vypise vsechny MotoPress sluzby (id, nazev, cena) a navrhne ID pro pejska a detskou postylku.')]
class MotoPressListServicesCommand extends Command
{
    public function __construct(private readonly MotoPressClient $client)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $services = $this->client->listServices();
        } catch (MotoPressApiException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($services === []) {
            $io->warning('MotoPress nevratil zadne sluzby.');

            return Command::SUCCESS;
        }

        $rows = [];
        $petIds = [];
        $cotIds = [];
        foreach ($services as $service) {
            $id = isset($service['id']) ? (int) $service['id'] : 0;
            $title = $this->extractTitle($service);
            $price = $service['price'] ?? '';
            $rows[] = [$id, $title, is_scalar($price) ? (string) $price : ''];

            if ($id > 0 && GuestRequestKeywords::mentionsPet($title)) {
                $petIds[] = $id;
            }
            if ($id > 0 && GuestRequestKeywords::mentionsBabyCot($title)) {
                $cotIds[] = $id;
            }
        }

        $io->table(['ID', 'Nazev', 'Cena'], $rows);

        $io->section('Doporucene .env hodnoty');
        $io->writeln(sprintf('MOTOPRESS_PET_SERVICE_IDS=%s', implode(',', $petIds)));
        $io->writeln(sprintf('MOTOPRESS_BABY_COT_SERVICE_IDS=%s', implode(',', $cotIds)));

        if ($cotIds === []) {
            $io->note('Postylka nenalezena podle nazvu — pridej ji v MotoPressu jako Service ("Detska postylka") nebo doplnoval ID rucne.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $service
     */
    private function extractTitle(array $service): string
    {
        $title = $service['title'] ?? $service['name'] ?? '';
        if (is_array($title)) {
            // WP REST obcas vraci {rendered: "..."} pro titulek
            $title = $title['rendered'] ?? '';
        }

        return is_string($title) ? trim($title) : '';
    }
}
