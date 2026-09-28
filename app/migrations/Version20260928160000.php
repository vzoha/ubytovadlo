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
 * Veřejné odkazy na PDF faktury (sdílení přes WhatsApp, SMS, chat).
 */
final class Version20260928160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'invoice_link';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invoice_link (id INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, last_opened_at DATETIME DEFAULT NULL, channel VARCHAR(16) DEFAULT NULL, invoice_id INT NOT NULL, INDEX IDX_3469FB942989F1FD (invoice_id), UNIQUE INDEX uniq_invoice_link_token_hash (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE invoice_link ADD CONSTRAINT FK_3469FB942989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE invoice_link');
    }
}
