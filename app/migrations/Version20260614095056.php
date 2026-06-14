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
 * Původ PDF faktury (invoice.pdf_source): generated/external. Externí (importované)
 * PDF se neregeneruje. Výchozí 'generated' — stávající faktury zůstávají regenerovatelné;
 * importované se označí externě (per-instance backfill).
 */
final class Version20260614095056 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'invoice.pdf_source — původ PDF (generated/external) pro ochranu importovaných faktur';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE invoice ADD pdf_source VARCHAR(16) DEFAULT 'generated' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP pdf_source');
    }
}
