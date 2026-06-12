<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Datová oprava: faktura 2026007 má chybně currency=EUR, ale částka 8 940,41
 * je evidentně v CZK (řádek "Ubytovací služby", rezervace 364,32 EUR × kurz).
 * Mátlo to ekonomický přehled (příjem se bral jako EUR k přepočtu).
 *
 * Starší faktury z původní fakturace (čísla YYMMDD###) s currency=EUR necháváme —
 * jejich částky jsou skutečně v EUR, ekonomika je přepočítává kurzem.
 */
final class Version20260607165314 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Oprava chybné měny faktury 2026007 (EUR → CZK, částka už v CZK je)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE invoice SET currency = 'CZK' WHERE number = '2026007' AND currency = 'EUR' AND total_amount > 1000");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE invoice SET currency = 'EUR' WHERE number = '2026007' AND currency = 'CZK' AND total_amount > 1000");
    }
}
