<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Cashflow;

use App\Entity\Account;
use App\Enum\LedgerEntryType;
use App\Repository\LedgerEntryRepository;
use App\Repository\ReservationReceiptRepository;

/**
 * Očekávaný stav účtu k datu (v celých Kč):
 *   opening + přijaté platby (ReservationReceipt) + příchozí převody
 *   − výdaje − odchozí převody ± korekce.
 *
 * Nespárované příchozí platby (bez navázané rezervace) se ZÁMĚRNĚ nepočítají:
 * na účet chodí i příjmy nesouvisející s pronájmem (soukromé, vklady), takže
 * dokud platbu někdo ručně nespáruje s rezervací (→ ReservationReceipt) nebo
 * nezaúčtuje ručně, do cashflow pronájmu nepatří. Zbytek srovná uzávěrka.
 */
final class AccountBalanceCalculator
{
    public function __construct(
        private readonly ReservationReceiptRepository $receipts,
        private readonly LedgerEntryRepository $ledger,
    ) {
    }

    public function balance(Account $account, ?\DateTimeImmutable $upTo = null): int
    {
        // Počáteční stav je zůstatek k openingDate → počítáme jen pohyby od té doby;
        // cokoliv staršího už je v počátečním stavu (a nesmí se odečíst znovu).
        $from = $account->getOpeningDate();
        $balance = $account->getOpeningBalanceCzk();

        // Platby s datem přijetí v okně [openingDate, upTo]. U OTA je received_on
        // datum odjezdu → minulé pobyty se počítají (výplata už dorazila), budoucí
        // ne (caller předá upTo = dnes). Odhad vs. reálná částka gate neřídí.
        foreach ($this->receipts->findReceivedForAccount($account, $from, $upTo) as $receipt) {
            $balance += self::toKc($receipt->getAmountCzk());
        }

        foreach ($this->ledger->findTouchingAccount($account, $from, $upTo) as $entry) {
            $balance += $this->ledgerEffect($account, $entry);
        }

        return $balance;
    }

    private function ledgerEffect(Account $account, \App\Entity\LedgerEntry $entry): int
    {
        $accountId = $account->getId();

        return match ($entry->getType()) {
            LedgerEntryType::EXPENSE => -$entry->getAmountCzk(),
            LedgerEntryType::ADJUSTMENT => $entry->getAmountCzk(),
            LedgerEntryType::TRANSFER => match (true) {
                $entry->getAccount()->getId() === $accountId => -$entry->getAmountCzk(),
                $entry->getCounterAccount()?->getId() === $accountId => $entry->getAmountCzk(),
                default => 0,
            },
        };
    }

    private static function toKc(string $decimal): int
    {
        return (int) round((float) $decimal);
    }
}
