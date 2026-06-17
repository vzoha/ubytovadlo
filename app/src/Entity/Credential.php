<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CredentialRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Přístupový údaj (IMAP, MotoPress…) zadaný v UI a uložený šifrovaně (App\Credential).
 * Oddělené od plaintextového `setting` store — hodnota je vždy ciphertext.
 */
#[ORM\Entity(repositoryClass: CredentialRepository::class)]
#[ORM\Table(name: 'credential')]
class Credential
{
    #[ORM\Id]
    #[ORM\Column(name: '`key`', length: 64)]
    private string $key;

    #[ORM\Column(name: 'value_encrypted', type: Types::TEXT)]
    private string $valueEncrypted;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $key, string $valueEncrypted)
    {
        $this->key = $key;
        $this->valueEncrypted = $valueEncrypted;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValueEncrypted(): string
    {
        return $this->valueEncrypted;
    }

    public function setValueEncrypted(string $valueEncrypted): self
    {
        $this->valueEncrypted = $valueEncrypted;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
