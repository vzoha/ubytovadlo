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
 * Dorovnání způsobu platby na kódy u faktur z dřívější evidence.
 *
 * Historické doklady nesou způsob platby volným textem („bankovní převod",
 * „hotovost", „kartou online"), protože se zapisoval ručně. Převádí se podle
 * významu; co nesedí na žádný vzor, spadne na převod — peníze dorazily na účet.
 */
final class Version20260907180000 extends AbstractMigration
{
    /** Vzor (LIKE, malá písmena) → kód. Pořadí rozhoduje, první shoda vyhrává. */
    private const array PATTERNS = [
        '%zprostředkovatel%' => 'prepaid_intermediary',
        '%hotov%' => 'cash',
        '%kart%' => 'card_online',
        '%online%' => 'card_online',
    ];

    private const array CODES = ['bank_transfer', 'cash', 'card_online', 'prepaid_intermediary'];

    public function getDescription(): string
    {
        return 'invoice.payment_method — převod volných textů z dřívější evidence na kódy';
    }

    public function up(Schema $schema): void
    {
        $codes = "'" . implode("', '", self::CODES) . "'";

        foreach (self::PATTERNS as $pattern => $code) {
            $this->addSql(
                'UPDATE invoice SET payment_method = :code'
                . ' WHERE payment_method NOT IN (' . $codes . ') AND LOWER(payment_method) LIKE :pattern',
                ['code' => $code, 'pattern' => $pattern],
            );
        }

        $this->addSql('UPDATE invoice SET payment_method = :code WHERE payment_method NOT IN (' . $codes . ')', ['code' => 'bank_transfer']);
    }

    public function down(Schema $schema): void
    {
        // Původní volné texty se neobnovují — jejich informační hodnota je v kódech.
    }
}
