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
 * guest_document — adresa bydliště hosta je sdílená pro české i zahraniční hosty
 * (evidenční kniha i Ubyport), proto obecný název sloupce residence_address.
 */
final class Version20260718120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'guest_document.permanent_residence_abroad → residence_address.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guest_document CHANGE permanent_residence_abroad residence_address LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guest_document CHANGE residence_address permanent_residence_abroad LONGTEXT DEFAULT NULL');
    }
}
