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
 * Režim odesílání (auto/návrh/vypnuto) a nastavitelné časování zpráv hostům.
 * Nahrazuje binární příznak „enabled" třístavovým režimem a přidává kotvu,
 * posun ve dnech a hodinu odeslání.
 */
final class Version20260716120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'message_template — režim odesílání a časování zpráv hostům.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_template ADD mode VARCHAR(8) DEFAULT NULL, ADD anchor VARCHAR(16) DEFAULT NULL, ADD offset_days INT DEFAULT NULL, ADD send_at VARCHAR(5) DEFAULT NULL');

        // Zapnutá šablona → automatické odesílání. Vypnutá plánovaná zpráva se
        // dřív zakládala na osu, ale neodesílala → odpovídá režimu „jen návrh";
        // ostatní vypnuté zůstávají vypnuté.
        $this->addSql("UPDATE message_template SET mode = 'auto' WHERE enabled = 1");
        $this->addSql("UPDATE message_template SET mode = 'draft' WHERE enabled = 0 AND kind IN ('reservation_request', 'pre_arrival', 'post_stay', 'balance_reminder')");
        $this->addSql("UPDATE message_template SET mode = 'off' WHERE enabled = 0 AND kind NOT IN ('reservation_request', 'pre_arrival', 'post_stay', 'balance_reminder')");

        // Výchozí časování dle druhu, ať zprávy po zapnutí odejdou ve správný čas.
        $this->addSql("UPDATE message_template SET anchor = 'created', offset_days = 0 WHERE kind = 'reservation_request'");
        $this->addSql("UPDATE message_template SET anchor = 'check_in', offset_days = -3, send_at = '09:00' WHERE kind = 'pre_arrival'");
        $this->addSql("UPDATE message_template SET anchor = 'check_out', offset_days = 1, send_at = '10:00' WHERE kind = 'post_stay'");
        $this->addSql("UPDATE message_template SET anchor = 'check_in', offset_days = 0, send_at = '12:00' WHERE kind = 'balance_reminder'");

        $this->addSql('ALTER TABLE message_template CHANGE mode mode VARCHAR(8) NOT NULL');
        $this->addSql('ALTER TABLE message_template DROP enabled');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_template ADD enabled TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE message_template SET enabled = CASE WHEN mode = 'off' THEN 0 ELSE 1 END");
        $this->addSql('ALTER TABLE message_template DROP mode, DROP anchor, DROP offset_days, DROP send_at');
    }
}
