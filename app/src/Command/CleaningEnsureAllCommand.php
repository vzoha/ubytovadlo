<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CleaningRepository;
use App\Repository\ReservationRepository;
use App\Service\Cleaning\CleaningProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:cleaning:ensure-all', description: 'Doplní defaultní úklid (vlastní) pro všechny rezervace, které ho ještě nemají.')]
class CleaningEnsureAllCommand extends Command
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly CleaningRepository $cleanings,
        private readonly CleaningProvisioner $provisioner,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = 0;

        foreach ($this->reservations->findAll() as $reservation) {
            if ($this->cleanings->findForReservation($reservation) !== null) {
                continue;
            }
            $this->provisioner->ensureForReservation($reservation);
            $created++;
        }

        $this->em->flush();
        $io->success(sprintf('Doplněno %d nových úklidů.', $created));

        return Command::SUCCESS;
    }
}
