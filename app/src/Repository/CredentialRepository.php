<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Credential\CredentialCipher;
use App\Entity\Credential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Credential>
 */
class CredentialRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly CredentialCipher $cipher,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($registry, Credential::class);
    }

    /** Dešifrovaná hodnota, nebo null (klíč chybí / nejde dešifrovat). */
    public function getDecrypted(string $key): ?string
    {
        $credential = $this->find($key);
        if ($credential === null) {
            return null;
        }

        return $this->cipher->decrypt($credential->getValueEncrypted());
    }

    /** Zašifruje a uloží (upsert). Flush nechává na volajícím. */
    public function setEncrypted(string $key, #[\SensitiveParameter] string $plaintext): void
    {
        $encrypted = $this->cipher->encrypt($plaintext);
        $credential = $this->find($key);
        if ($credential === null) {
            $this->em->persist(new Credential($key, $encrypted));

            return;
        }
        $credential->setValueEncrypted($encrypted);
    }
}
