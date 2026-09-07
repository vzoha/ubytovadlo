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
 * Způsob platby jako kód (enum) a splatnost jen tam, kde vznikl dluh.
 *
 * Faktura za pobyt prodaný přes portál je doklad o platbě, kterou host složil
 * portálu ještě před vystavením: nemá splatnost a je uhrazená dnem vystavení.
 */
final class Version20260907140000 extends AbstractMigration
{
    private const array METHOD_CODES = [
        'převodem' => 'bank_transfer',
        'hotově' => 'cash',
        'převodem – zprostředkovatel' => 'prepaid_intermediary',
    ];

    public function getDescription(): string
    {
        return 'invoice.payment_method jako kód, splatnost nepovinná u platby přes zprostředkovatele';
    }

    public function up(Schema $schema): void
    {
        foreach (self::METHOD_CODES as $label => $code) {
            $this->addSql('UPDATE invoice SET payment_method = :code WHERE payment_method = :label', ['code' => $code, 'label' => $label]);
        }

        $this->addSql('ALTER TABLE invoice CHANGE due_at due_at DATE DEFAULT NULL');

        // Doklad za pobyt z portálu: platba přišla dřív než faktura, dluh nevznikl.
        $this->addSql("UPDATE invoice SET paid_at = issued_at WHERE payment_method = 'prepaid_intermediary' AND paid_at IS NULL");
        $this->addSql("UPDATE invoice SET due_at = NULL WHERE payment_method = 'prepaid_intermediary'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE invoice SET due_at = issued_at WHERE due_at IS NULL');
        $this->addSql('ALTER TABLE invoice CHANGE due_at due_at DATE NOT NULL');

        foreach (self::METHOD_CODES as $label => $code) {
            $this->addSql('UPDATE invoice SET payment_method = :label WHERE payment_method = :code', ['code' => $code, 'label' => $label]);
        }
    }
}
