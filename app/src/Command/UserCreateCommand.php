<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
            ->addArgument('password', InputArgument::OPTIONAL, 'Heslo (jinak prompt)')
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED, 'Role noveho uzivatele: admin | spravce | uklizecka', 'admin');
    }

    private function resolveRole(InputInterface $input): UserRole
    {
        return match (strtolower((string) $input->getOption('role'))) {
            'admin' => UserRole::ADMIN,
            'spravce', 'manager' => UserRole::MANAGER,
            'uklizecka', 'cleaner' => UserRole::CLEANER,
            default => throw new \InvalidArgumentException('Neznama role. Pouzij admin | spravce | uklizecka.'),
        };
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

        $user = $this->users->findOneBy(['email' => $email]);
        if ($user === null) {
            $user = new User($email);
            $user->setRole($this->resolveRole($input));
        }
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Uzivatel %s ulozen (%s).', $email, $user->getRole()->label()));

        return Command::SUCCESS;
    }
}
