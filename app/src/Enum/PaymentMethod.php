<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Jak je faktura vypořádaná vůči hostovi. Rozhoduje o splatnosti, číslu účtu
 * s QR kódem a o tom, na který účet sedne příjem.
 *
 * Doklad mluví jen o vztahu host ↔ ubytovatel. Peníze od portálu (OTA výplata)
 * jsou samostatná událost na rezervaci ({@see \App\Cashflow\IncomeUpserter}),
 * do faktury nevstupují.
 */
enum PaymentMethod: string
{
    case BANK_TRANSFER = 'bank_transfer';
    case CASH = 'cash';
    /** Host zaplatil kartou přes platební bránu — peníze dorazí na účet, doklad nepotřebuje QR. */
    case CARD_ONLINE = 'card_online';
    /** Host zaplatil portálu předem (Airbnb, Booking); ubytovateli dorazí výplata. */
    case PREPAID_INTERMEDIARY = 'prepaid_intermediary';

    /** Text tištěný na faktuře. */
    public function label(): string
    {
        return match ($this) {
            self::BANK_TRANSFER => 'převodem',
            self::CASH => 'hotově',
            self::CARD_ONLINE => 'kartou online',
            self::PREPAID_INTERMEDIARY => 'přes zprostředkovatele',
        };
    }

    /** Splatnost má doklad, který na platbu teprve čeká. */
    public function hasDueDate(): bool
    {
        return $this !== self::PREPAID_INTERMEDIARY;
    }

    /** Platba je přijatá dřív, než doklad vznikne — vystavuje se rovnou uhrazený. */
    public function settledOnIssue(): bool
    {
        return $this === self::PREPAID_INTERMEDIARY;
    }
}
