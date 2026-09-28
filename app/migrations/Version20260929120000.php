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
 * Zašifrovaný token odkazu na fakturu — platný odkaz jde poslat znovu.
 */
final class Version20260929120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'invoice_link.token_encrypted';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_link ADD token_encrypted LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_link DROP token_encrypted');
    }
}
