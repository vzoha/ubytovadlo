<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'reservation: booked_at (kdy host objednal v MotoPressu, date_gmt)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD booked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP booked_at');
    }
}
