<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260530130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ubyport: per-rezervační stav nahlášení (export UNL + doručenka PDF) na reservation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                ALTER TABLE reservation
                    ADD ubyport_exported_at DATETIME DEFAULT NULL,
                    ADD ubyport_confirmed_at DATETIME DEFAULT NULL,
                    ADD ubyport_receipt_filename VARCHAR(255) DEFAULT NULL,
                    ADD ubyport_receipt_accepted INT DEFAULT NULL,
                    ADD ubyport_receipt_rejected INT DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                ALTER TABLE reservation
                    DROP ubyport_exported_at,
                    DROP ubyport_confirmed_at,
                    DROP ubyport_receipt_filename,
                    DROP ubyport_receipt_accepted,
                    DROP ubyport_receipt_rejected
            SQL);
    }
}
