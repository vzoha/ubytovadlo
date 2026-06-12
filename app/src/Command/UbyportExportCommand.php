<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AccommodationProfileRepository;
use App\Repository\GuestDocumentRepository;
use App\Ubyport\UnlExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vygeneruje UNL soubor s cizinci k nahlášení pro Ubyport
 * (ubyport.policie.cz → "Import dat (UNL soubor)").
 * Soubor se uloží do var/exports/ a majitelka ho ručně nahraje.
 *
 * Dva režimy (stejná logika výběru jako web /ubyport/export):
 *   - bez --from/--to = ROLLING: potvrzení cizinci bez ubyportReportedAt; po exportu
 *     se označí jako nahlášení (stejné jako stažení UNL na webu). Vhodné pro cron.
 *   - s --from/--to = RE-EXPORT za období: vybere podle data pobytu bez ohledu na
 *     značku nahlášení a NEoznačuje — slouží k opětovnému vygenerování historie.
 */
#[AsCommand(name: 'app:ubyport:export', description: 'Vygeneruje UNL soubor s cizinci k nahlášení pro Ubyport.')]
class UbyportExportCommand extends Command
{
    public function __construct(
        private readonly AccommodationProfileRepository $profiles,
        private readonly GuestDocumentRepository $documents,
        private readonly UnlExporter $exporter,
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Re-export: začátek období (YYYY-MM-DD). Bez období jede rolling fronta.');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Re-export: konec období (YYYY-MM-DD). Použij spolu s --from.');
        $this->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Adresář pro výstup', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $profile = $this->profiles->getSingleton();
        if ($profile === null) {
            $io->error('Není vyplněný AccommodationProfile (IDUB + adresa). Doplň v admin UI.');

            return Command::FAILURE;
        }

        $fromOpt = $input->getOption('from');
        $toOpt = $input->getOption('to');
        $rolling = $fromOpt === null && $toOpt === null;

        if ($rolling) {
            $docs = $this->documents->findToReport();
            $emptyMsg = 'Nikdo k nahlášení (potvrzení cizinci bez značky nahlášení).';
        } else {
            [$from, $to] = $this->resolvePeriod($fromOpt, $toOpt, $io);
            if ($from === null || $to === null) {
                return Command::FAILURE;
            }
            $docs = $this->documents->findForUbyportExport($from, $to);
            $emptyMsg = sprintf('Nikdo k nahlášení v období %s – %s.', $from->format('Y-m-d'), $to->format('Y-m-d'));
        }

        if ($docs === []) {
            $io->warning($emptyMsg);

            return Command::SUCCESS;
        }

        $generatedAt = new \DateTimeImmutable();
        $result = $this->exporter->build($profile, $docs, $generatedAt);

        $dir = $input->getOption('output-dir') ?? $this->projectDir . '/var/exports';
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            $io->error(sprintf('Nelze vytvořit adresář: %s', $dir));

            return Command::FAILURE;
        }

        $path = $dir . '/' . $result->filename;
        if (file_put_contents($path, $result->content) === false) {
            $io->error(sprintf('Nelze zapsat soubor: %s', $path));

            return Command::FAILURE;
        }

        if ($rolling) {
            foreach ($docs as $doc) {
                $doc->markUbyportReported($generatedAt);
                $reservation = $doc->getReservation();
                if ($reservation->getUbyportExportedAt() === null) {
                    $reservation->markUbyportExported($generatedAt);
                }
            }
            $this->em->flush();
            $io->note(sprintf('Označeno %d záznam(ů) jako nahlášené (rolling).', $result->guestCount));
        } else {
            $io->note('Re-export za období — značka nahlášení (ubyportReportedAt) se nemění.');
        }

        $io->success(sprintf(
            'Vygenerováno %d záznam(ů) → %s%sNahrát na https://ubyport.policie.cz (Import dat).',
            $result->guestCount,
            $path,
            PHP_EOL,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function resolvePeriod(?string $fromOpt, ?string $toOpt, SymfonyStyle $io): array
    {
        if ($fromOpt === null || $toOpt === null) {
            $io->error('Pro re-export použij --from i --to současně. Bez období jede rolling fronta.');

            return [null, null];
        }

        try {
            $from = new \DateTimeImmutable($fromOpt . ' 00:00:00');
            $to = new \DateTimeImmutable($toOpt . ' 23:59:59');
        } catch (\Exception $e) {
            $io->error('Neplatný formát data, očekávám YYYY-MM-DD.');

            return [null, null];
        }

        if ($from > $to) {
            $io->error('--from musí být před --to.');

            return [null, null];
        }

        return [$from, $to];
    }
}
