<?php

declare(strict_types=1);

namespace App\Command;

use App\Email\EmailDispatcher;
use App\Email\EmlReader;
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
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

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
