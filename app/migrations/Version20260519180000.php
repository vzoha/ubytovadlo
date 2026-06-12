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

final class Version20260519180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ubyport: nationality číselník, accommodation_profile (hlavička UNL), guest_document (data hostů), checkin token + purpose_of_stay na rezervaci.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE nationality (
                    code VARCHAR(3) NOT NULL,
                    name_cs VARCHAR(128) NOT NULL,
                    name_en VARCHAR(128) NOT NULL,
                    PRIMARY KEY(code)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
                CREATE TABLE accommodation_profile (
                    id INT AUTO_INCREMENT NOT NULL,
                    idub VARCHAR(12) NOT NULL,
                    kod VARCHAR(5) NOT NULL,
                    nazev VARCHAR(255) NOT NULL,
                    spojeni VARCHAR(255) NOT NULL,
                    okres VARCHAR(128) NOT NULL,
                    obec VARCHAR(128) NOT NULL,
                    cast_obce VARCHAR(128) DEFAULT NULL,
                    ulice VARCHAR(128) DEFAULT NULL,
                    cp VARCHAR(16) DEFAULT NULL,
                    co VARCHAR(16) DEFAULT NULL,
                    psc VARCHAR(8) NOT NULL,
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
                CREATE TABLE guest_document (
                    id INT AUTO_INCREMENT NOT NULL,
                    reservation_id INT NOT NULL,
                    last_name VARCHAR(64) NOT NULL,
                    first_name VARCHAR(64) NOT NULL,
                    birth_date DATE NOT NULL,
                    nationality_code VARCHAR(3) DEFAULT NULL,
                    is_czech_citizen TINYINT(1) DEFAULT 0 NOT NULL,
                    document_type VARCHAR(32) DEFAULT NULL,
                    document_number VARCHAR(32) DEFAULT NULL,
                    visa_number VARCHAR(32) DEFAULT NULL,
                    permanent_residence_abroad LONGTEXT DEFAULT NULL,
                    note LONGTEXT DEFAULT NULL,
                    confirmed_at DATETIME DEFAULT NULL,
                    ubyport_reported_at DATETIME DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    INDEX IDX_A7434209B83297E7 (reservation_id),
                    INDEX idx_guest_document_confirmed (confirmed_at),
                    INDEX idx_guest_document_reported (ubyport_reported_at),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
                ALTER TABLE guest_document
                ADD CONSTRAINT fk_guest_document_reservation
                FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE CASCADE
            SQL);

        $this->addSql('ALTER TABLE reservation ADD checkin_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation ADD checkin_completed_at DATETIME DEFAULT NULL');
        $this->addSql("ALTER TABLE reservation ADD ubyport_purpose_of_stay VARCHAR(2) DEFAULT '10' NOT NULL");
        $this->addSql('CREATE UNIQUE INDEX uniq_reservation_checkin_token ON reservation (checkin_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_reservation_checkin_token ON reservation');
        $this->addSql('ALTER TABLE reservation DROP checkin_token, DROP checkin_completed_at, DROP ubyport_purpose_of_stay');
        $this->addSql('DROP TABLE guest_document');
        $this->addSql('DROP TABLE accommodation_profile');
        $this->addSql('DROP TABLE nationality');
    }
}
