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

/** Adresa portálu (guest.booking.com a spol.) se drží zvlášť od vlastní adresy hosta. */
final class Version20260907090000 extends AbstractMigration
{
    private const array PORTAL_DOMAINS = ['guest.booking.com', 'guest.airbnb.com'];

    public function getDescription(): string
    {
        return 'reservation.guest_portal_email — adresa portálu odděleně od e-mailu hosta';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD guest_portal_email VARCHAR(255) DEFAULT NULL');

        foreach (self::PORTAL_DOMAINS as $domain) {
            $this->addSql(
                'UPDATE reservation SET guest_portal_email = guest_email, guest_email = NULL WHERE guest_email LIKE :suffix',
                ['suffix' => '%@' . $domain],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE reservation SET guest_email = guest_portal_email WHERE guest_email IS NULL AND guest_portal_email IS NOT NULL');
        $this->addSql('ALTER TABLE reservation DROP guest_portal_email');
    }
}
