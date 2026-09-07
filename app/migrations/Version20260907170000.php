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
 * Den uskutečnění plnění (DUZP) na faktuře.
 *
 * U pobytu je to jeho konec, u zálohy den přijetí platby. Rozhoduje o tom,
 * do kterého zdaňovacího období spadá výstupní DPH — datum vystavení se od něj
 * běžně liší, protože faktura se vystavuje během pobytu.
 */
final class Version20260907170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'invoice.duzp — den uskutečnění plnění';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD duzp DATE DEFAULT NULL');

        // Záloha se zdaňuje dnem přijetí platby, ostatní doklady koncem pobytu.
        $this->addSql("UPDATE invoice SET duzp = paid_at WHERE type = 'deposit'");
        $this->addSql(
            'UPDATE invoice i JOIN reservation r ON r.id = i.reservation_id'
            . " SET i.duzp = COALESCE(r.check_out, i.issued_at) WHERE i.type <> 'deposit'",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP duzp');
    }
}
