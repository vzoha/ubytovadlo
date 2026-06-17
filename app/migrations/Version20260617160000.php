<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tabulka credential — šifrované přístupové údaje (IMAP, MotoPress) zadané v UI.
 */
final class Version20260617160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabulka credential — šifrovaný store přístupových údajů.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE credential (`key` VARCHAR(64) NOT NULL, value_encrypted LONGTEXT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (`key`)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE credential');
    }
}
