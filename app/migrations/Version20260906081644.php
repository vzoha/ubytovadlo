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
 * Čas, kdy si host zvolil jazyk komunikace přepínačem v check-inu.
 */
final class Version20260906081644 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'reservation.guest_locale_chosen_at — volba jazyka hostem v check-inu';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD guest_locale_chosen_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP guest_locale_chosen_at');
    }
}
