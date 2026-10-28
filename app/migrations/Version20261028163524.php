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

final class Version20261028163524 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rychlá zpráva navázaná na druh automatické zprávy a zápis na ose navázaný na akci.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quick_message ADD kind VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation_note ADD action_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation_note ADD CONSTRAINT FK_D984377C9D32F035 FOREIGN KEY (action_id) REFERENCES reservation_action (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D984377C9D32F035 ON reservation_note (action_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_note DROP FOREIGN KEY FK_D984377C9D32F035');
        $this->addSql('DROP INDEX IDX_D984377C9D32F035 ON reservation_note');
        $this->addSql('ALTER TABLE reservation_note DROP action_id');
        $this->addSql('ALTER TABLE quick_message DROP kind');
    }
}
