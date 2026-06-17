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
 * Tabulka payment — příchozí platby z bankovní notifikace (párování s rezervací podle VS).
 */
final class Version20260617152851 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabulka payment — evidence a párování příchozích plateb (notifikace ČS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment (id INT AUTO_INCREMENT NOT NULL, source VARCHAR(16) NOT NULL, amount NUMERIC(12, 2) NOT NULL, currency VARCHAR(3) NOT NULL, variable_symbol VARCHAR(32) DEFAULT NULL, constant_symbol VARCHAR(32) DEFAULT NULL, counterparty_account VARCHAR(64) DEFAULT NULL, received_at DATETIME NOT NULL, email_message_id VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, reservation_id INT DEFAULT NULL, invoice_id INT DEFAULT NULL, INDEX IDX_6D28840DB83297E7 (reservation_id), INDEX IDX_6D28840D2989F1FD (invoice_id), INDEX idx_payment_variable_symbol (variable_symbol), UNIQUE INDEX uniq_payment_email_message_id (email_message_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840DB83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D2989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840DB83297E7');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D2989F1FD');
        $this->addSql('DROP TABLE payment');
    }
}
