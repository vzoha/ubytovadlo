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
 * Výstupní DPH na fakturách: sazba na řádku faktury a snímek daňového profilu
 * a celkového základu/DPH na faktuře (plátce DPH).
 */
final class Version20260710220147 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Výstupní DPH na fakturách (sazba na řádku + snímek profilu a základu/DPH na faktuře)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD tax_profile_snapshot VARCHAR(32) DEFAULT NULL, ADD vat_base_total NUMERIC(12, 2) DEFAULT NULL, ADD vat_amount_total NUMERIC(12, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_line ADD vat_rate NUMERIC(5, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP tax_profile_snapshot, DROP vat_base_total, DROP vat_amount_total');
        $this->addSql('ALTER TABLE invoice_line DROP vat_rate');
    }
}
