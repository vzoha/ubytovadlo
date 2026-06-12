<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260530101404 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Airbnb real payout fields (amount, sent date, reference) to reservation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE reservation
                ADD payout_amount NUMERIC(10, 2) DEFAULT NULL,
                ADD payout_sent_at DATE DEFAULT NULL,
                ADD payout_reference VARCHAR(64) DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE reservation
                DROP payout_amount,
                DROP payout_sent_at,
                DROP payout_reference
            SQL);
    }
}
