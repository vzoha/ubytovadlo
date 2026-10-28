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

final class Version20261028130549 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Korespondence s hosty (guest_email) a Message-ID odeslaných zpráv hostům.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE guest_email (id INT AUTO_INCREMENT NOT NULL, direction VARCHAR(8) NOT NULL, message_id VARCHAR(255) NOT NULL, thread_ids JSON NOT NULL, counterpart_email VARCHAR(255) DEFAULT NULL, counterpart_name VARCHAR(255) DEFAULT NULL, subject VARCHAR(512) NOT NULL, sent_at DATETIME NOT NULL, body MEDIUMTEXT NOT NULL, attachment_names JSON NOT NULL, answered TINYINT NOT NULL, created_at DATETIME NOT NULL, customer_id INT DEFAULT NULL, reservation_id INT DEFAULT NULL, INDEX IDX_F26CEB609395C3F3 (customer_id), INDEX IDX_F26CEB60B83297E7 (reservation_id), INDEX idx_guest_email_counterpart (counterpart_email), INDEX idx_guest_email_sent_at (sent_at), UNIQUE INDEX uniq_guest_email_message_id (message_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE guest_email ADD CONSTRAINT FK_F26CEB609395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE guest_email ADD CONSTRAINT FK_F26CEB60B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE guest_message ADD message_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_guest_message_message_id ON guest_message (message_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guest_email DROP FOREIGN KEY FK_F26CEB609395C3F3');
        $this->addSql('ALTER TABLE guest_email DROP FOREIGN KEY FK_F26CEB60B83297E7');
        $this->addSql('DROP TABLE guest_email');
        $this->addSql('DROP INDEX idx_guest_message_message_id ON guest_message');
        $this->addSql('ALTER TABLE guest_message DROP message_id');
    }
}
