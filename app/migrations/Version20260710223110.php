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
 * DPH období u plátce: snímek výstupní DPH a odpočtu na vstupu za měsíc.
 */
final class Version20260710223110 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'VatPeriod: výstupní DPH a odpočet na vstupu (plátce DPH)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vat_period ADD output_vat_czk NUMERIC(12, 2) DEFAULT NULL, ADD input_deductible_czk NUMERIC(12, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vat_period DROP output_vat_czk, DROP input_deductible_czk');
    }
}
