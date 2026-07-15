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
 * Uživatelsky definované rychlé zprávy pro předvyplnění SMS/WhatsApp.
 */
final class Version20260715155242 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabulka quick_message — rychlé zprávy pro SMS/WhatsApp.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE quick_message (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(64) NOT NULL, body LONGTEXT NOT NULL, sort_order INT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quick_message');
    }
}
