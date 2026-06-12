<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260530120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link airbnb_statement to a reservation (nullable FK) for per-reservation receipt tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE airbnb_statement
                ADD reservation_id INT DEFAULT NULL,
                ADD CONSTRAINT FK_airbnb_statement_reservation
                    FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE SET NULL
            SQL);
        $this->addSql('CREATE INDEX idx_airbnb_statement_reservation ON airbnb_statement (reservation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE airbnb_statement DROP FOREIGN KEY FK_airbnb_statement_reservation');
        $this->addSql('DROP INDEX idx_airbnb_statement_reservation ON airbnb_statement');
        $this->addSql('ALTER TABLE airbnb_statement DROP reservation_id');
    }
}
