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
 * Šablony zpráv hostům dostávají jazyk: jeden řádek na druh zprávy a jazyk.
 * Existující řádky jsou české, proto default `cs`.
 */
final class Version20260830120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jazyk u šablon zpráv hostům (message_template.locale)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE message_template ADD locale VARCHAR(5) DEFAULT 'cs' NOT NULL");
        $this->addSql('DROP INDEX UNIQ_9E46DB923BC4BCD9 ON message_template');
        $this->addSql('CREATE UNIQUE INDEX uniq_message_template_kind_locale ON message_template (kind, locale)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_message_template_kind_locale ON message_template');
        $this->addSql('ALTER TABLE message_template DROP locale');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9E46DB923BC4BCD9 ON message_template (kind)');
    }
}
