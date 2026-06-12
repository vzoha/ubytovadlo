<?php

declare(strict_types=1);

namespace App\Command;

use App\MotoPress\MotoPressApiException;
use App\MotoPress\MotoPressClient;
use App\MotoPress\MotoPressSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:motopress:sync', description: 'Stahne rezervace z MotoPressu (vlastni web).')]
class MotoPressSyncCommand extends Command
{
    public function __construct(
        private readonly MotoPressSync $sync,
        private readonly MotoPressClient $client,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Stahnout jen rezervace od datumu (YYYY-MM-DD), filtruje podle check_in_date')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nic neuklada, jen vypise.')
            ->addOption('debug', null, InputOption::VALUE_REQUIRED, 'Vypise raw JSON pro dane MotoPress booking ID (cary-separated) a skonci.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $debug = $input->getOption('debug');
        if (is_string($debug) && $debug !== '') {
            foreach (explode(',', $debug) as $rawId) {
                $id = (int) trim($rawId);
                if ($id <= 0) {
                    continue;
                }
                try {
                    $data = $this->client->getBooking($id);
                } catch (MotoPressApiException $e) {
                    $io->error(sprintf('Booking %d: %s', $id, $e->getMessage()));
                    continue;
                }
                $io->section(sprintf('MotoPress booking #%d', $id));
                $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            }

            return Command::SUCCESS;
        }

        $query = [];
        $since = $input->getOption('since');
        if (is_string($since) && $since !== '') {
            $query['check_in_date'] = $since;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $result = $this->sync->sync($query, $dryRun);
        } catch (MotoPressApiException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s — total %d, novych %d, upraveno %d, beze zmeny %d, preskoceno %d.',
            $dryRun ? 'DRY RUN' : 'Sync hotov',
            $result->total,
            $result->created,
            $result->updated,
            $result->unchanged,
            $result->skipped,
        ));

        return Command::SUCCESS;
    }
}
