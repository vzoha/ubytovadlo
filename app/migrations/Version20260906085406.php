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

/** Způsob vyřízení akce: zpráva odešla poštou, nebo ji ubytovatelka vyřídila mimo aplikaci. */
final class Version20260906085406 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'reservation_action.delivery — jak byla uzavřená zpráva doručena';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_action ADD delivery VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_action DROP delivery');
    }
}
