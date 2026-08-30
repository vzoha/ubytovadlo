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
 * Ruční volba jazyka zpráv u rezervace. Prázdná hodnota = jazyk se odvodí
 * ze země v adrese hosta.
 */
final class Version20260830130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jazyk zpráv u rezervace (reservation.guest_locale)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD guest_locale VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP guest_locale');
    }
}
