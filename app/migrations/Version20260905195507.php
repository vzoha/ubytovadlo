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
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260905195507 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Volitelný název zařízení pro hlášení na Ubyport (prázdné = název, který zná host).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accommodation_profile ADD nazev_hlaseni VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accommodation_profile DROP nazev_hlaseni');
    }
}
