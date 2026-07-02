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
use App\Enum\AccountType;
use App\Enum\LedgerEntryType;
use App\Repository\AccountRepository;
use App\Repository\LedgerEntryRepository;
use App\Repository\PaymentRepository;
use App\Repository\ReservationIncomeRepository;

/**
 * Očekávaný stav účtu k datu (v celých Kč):
 *   opening + příjmy (ReservationIncome) + nepřiřazené bankovní kredity
 *   + příchozí převody − výdaje − odchozí převody ± korekce.
 * Nepřiřazené platby (bez rezervace) se počítají jen na výchozí bankovní účet.
 */
final class AccountBalanceCalculator
{
    public function __construct(
        private readonly ReservationIncomeRepository $incomes,
        private readonly LedgerEntryRepository $ledger,
        private readonly PaymentRepository $payments,
        private readonly AccountRepository $accounts,
    ) {
    }

    public function balance(Account $account, ?\DateTimeImmutable $upTo = null): int
    {
        // Počáteční stav je zůstatek k openingDate → počítáme jen pohyby od té doby;
        // cokoliv staršího už je v počátečním stavu (a nesmí se odečíst znovu).
        $from = $account->getOpeningDate();
        $balance = $account->getOpeningBalanceCzk();

        // Příjmy s datem přijetí v okně [openingDate, upTo]. U OTA je received_on
        // datum odjezdu → minulé pobyty se počítají (výplata už dorazila), budoucí
        // ne (caller předá upTo = dnes). Odhad vs. reálná částka gate neřídí.
        foreach ($this->incomes->findReceivedForAccount($account, $from, $upTo) as $income) {
            $balance += self::toKc($income->getAmountCzk());
        }

        if ($account->getType() === AccountType::BANK && $this->isDefaultBank($account)) {
            foreach ($this->payments->findUnassignedCzk($from, $upTo) as $payment) {
                $balance += self::toKc($payment->getAmount());
            }
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

    private function isDefaultBank(Account $account): bool
    {
        return $this->accounts->findDefaultByType(AccountType::BANK)?->getId() === $account->getId();
    }

    private static function toKc(string $decimal): int
    {
        return (int) round((float) $decimal);
    }
}
