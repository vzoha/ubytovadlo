<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create', description: 'Vytvori nebo aktualizuje uzivatele.')]
class UserCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail uzivatele')
            ->addArgument('password', InputArgument::OPTIONAL, 'Heslo (jinak prompt)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $password = $input->getArgument('password');

        if (!is_string($password) || $password === '') {
            $password = $io->askHidden('Heslo', static function (?string $value): string {
                if ($value === null || strlen($value) < 8) {
                    throw new \RuntimeException('Heslo musi mit alespon 8 znaku.');
                }

                return $value;
            });
        }

        $user = $this->users->findOneBy(['email' => $email]) ?? new User($email);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Uzivatel %s ulozen.', $email));

        return Command::SUCCESS;
    }
}
