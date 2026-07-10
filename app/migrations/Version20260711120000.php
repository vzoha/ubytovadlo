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
 * Uživatelské role a přiřaditelná práva: příznak aktivního účtu + povýšení
 * dosavadních uživatelů na admina (dosud měli plný přístup).
 */
final class Version20260711120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'app_user.is_active + povyseni stavajicich uzivatelu na ROLE_ADMIN';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD is_active TINYINT(1) DEFAULT 1 NOT NULL');
        // Dosavadní účty byly de facto vlastník s plným přístupem → admin.
        $this->addSql('UPDATE app_user SET roles = \'["ROLE_ADMIN"]\' WHERE roles = \'["ROLE_USER"]\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE app_user SET roles = \'["ROLE_USER"]\' WHERE roles = \'["ROLE_ADMIN"]\'');
        $this->addSql('ALTER TABLE app_user DROP is_active');
    }
}
