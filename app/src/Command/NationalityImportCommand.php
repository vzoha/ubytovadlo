<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Nationality;
use App\Repository\NationalityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Idempotentní upsert číselníku zemí pro Ubyport z CSV
 * (kod;plný název státu;zkrácený název CZ;zkrácený název EN, oddělovač ';').
 * Zdroj: data/ubyport/staty_kod.csv (UTF-8 verze sources/ubyport/staty_kod.csv).
 */
#[AsCommand(name: 'app:nationality:import', description: 'Upsert nationality číselníku z CSV pro Ubyport.')]
class NationalityImportCommand extends Command
{
    public function __construct(
        private readonly NationalityRepository $nationalities,
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Cesta k CSV souboru', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $file = $input->getOption('file') ?? $this->projectDir . '/data/ubyport/staty_kod.csv';
        if (!is_file($file)) {
            $io->error(sprintf('Soubor nenalezen: %s', $file));

            return Command::FAILURE;
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            $io->error(sprintf('Nelze otevřít soubor: %s', $file));

            return Command::FAILURE;
        }

        try {
            $header = fgetcsv($handle, 0, ';', '"', '\\');
            if ($header === false || ($header[0] ?? null) !== 'kod') {
                $io->error('Neočekávaná hlavička CSV — první sloupec musí být "kod".');

                return Command::FAILURE;
            }

            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            $lineNum = 1;

            while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
                $lineNum++;
                if ($row === [null] || count($row) < 4) {
                    $skipped++;
                    continue;
                }

                $code = trim((string) $row[0]);
                $nameCs = trim((string) $row[1]);
                $nameEn = trim((string) $row[3]);

                if ($code === '' || strlen($code) !== 3) {
                    $io->warning(sprintf('Řádek %d: neplatný kód "%s", přeskakuji.', $lineNum, $code));
                    $skipped++;
                    continue;
                }

                $entity = $this->nationalities->find($code);
                if ($entity === null) {
                    $entity = new Nationality($code, $nameCs, $nameEn);
                    $this->em->persist($entity);
                    $inserted++;
                } elseif ($entity->getNameCs() !== $nameCs || $entity->getNameEn() !== $nameEn) {
                    $entity->setNameCs($nameCs);
                    $entity->setNameEn($nameEn);
                    $updated++;
                }
            }
        } finally {
            fclose($handle);
        }

        $this->em->flush();

        $io->success(sprintf(
            'Hotovo: %d nových, %d aktualizovaných, %d přeskočených.',
            $inserted,
            $updated,
            $skipped,
        ));

        return Command::SUCCESS;
    }
}
