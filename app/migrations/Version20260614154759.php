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
 * Šablony e-mailů hostům (override výchozích textů z kódu).
 */
final class Version20260614154759 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabulka message_template — editovatelné šablony zpráv hostům.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE message_template (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(32) NOT NULL, subject VARCHAR(255) NOT NULL, body_markdown LONGTEXT NOT NULL, enabled TINYINT NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_9E46DB923BC4BCD9 (kind), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE message_template');
    }
}
