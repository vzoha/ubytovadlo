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
 * Poznámka k zákazníkovi a dvojice zákazníků, které ubytovatel označil za
 * různé lidi (návrh na sloučení se pro ně neukáže).
 */
final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'customer.note + customer_distinct_pair';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer ADD note LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE TABLE customer_distinct_pair (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, first_id INT NOT NULL, second_id INT NOT NULL, INDEX IDX_5D3C476E84D625F (first_id), INDEX IDX_5D3C476FF961BCC (second_id), UNIQUE INDEX uniq_customer_distinct_pair (first_id, second_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE customer_distinct_pair ADD CONSTRAINT FK_5D3C476E84D625F FOREIGN KEY (first_id) REFERENCES customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_distinct_pair ADD CONSTRAINT FK_5D3C476FF961BCC FOREIGN KEY (second_id) REFERENCES customer (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE customer_distinct_pair');
        $this->addSql('ALTER TABLE customer DROP note');
    }
}
