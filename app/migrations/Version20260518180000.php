<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reservation: flag guests_split_manually — chrání ruční rozdělení dospělí/děti před přepisem z MotoPress.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD guests_split_manually TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP guests_split_manually');
    }
}
