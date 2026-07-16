<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

use App\Enum\BillingMode;
use App\Enum\DepositMode;
use App\Formatting\Money;
use App\Repository\SettingRepository;

/**
 * Pravidla zálohy pro toky, které ji vyžadují (web klasika, ruční rezervace).
 * Výše je fixní částka, procento z ceny, nebo žádná; k tomu splatnost. Vždy v CZK.
 *
 * Hodnoty se čtou z DB (settings `invoice.deposit.*`) — instance si zálohu nastaví
 * v UI (/nastaveni/dodavatel). Bez nastavení se záloha nevystavuje (computeAmount null).
 */
final class DepositConfig
{
    public const KEY_MODE = 'invoice.deposit.mode';
    public const KEY_VALUE = 'invoice.deposit.value';
    public const KEY_DUE_DAYS = 'invoice.deposit.due_days';

    public const DEFAULT_DUE_DAYS = 2;

    public function __construct(
        private readonly SettingRepository $settings,
    ) {
    }

    public function mode(): DepositMode
    {
        $stored = $this->settings->getString(self::KEY_MODE);

        return $stored === null ? DepositMode::FIXED : (DepositMode::tryFrom($stored) ?? DepositMode::FIXED);
    }

    /** Bereme zálohu vůbec? (Fixní/procento ano, „bez zálohy" ne.) */
    public function enabled(): bool
    {
        return $this->mode() !== DepositMode::NONE;
    }

    /** Splatnost zálohové faktury ve dnech od vystavení. */
    public function dueDays(): int
    {
        $days = $this->settings->getInt(self::KEY_DUE_DAYS, self::DEFAULT_DUE_DAYS);

        return $days >= 0 ? $days : self::DEFAULT_DUE_DAYS;
    }

    /** Má tato rezervace (podle režimu) reálně dostat zálohu? */
    public function appliesTo(?BillingMode $mode): bool
    {
        return $mode?->requiresDeposit() === true && $this->enabled();
    }

    /**
     * Výše zálohy v CZK pro danou cenu rezervace, nebo null když se záloha nebere
     * (režim „bez zálohy"), nevyjde kladná (chybí/nesmyslné nastavení) nebo ji
     * u procenta nelze spočítat (chybí cena). Záloha se zastropuje na cenu, aby
     * doplatek nikdy nevyšel záporný.
     */
    public function computeAmount(?string $totalCzk): ?string
    {
        $amount = match ($this->mode()) {
            DepositMode::NONE => null,
            DepositMode::FIXED => $this->fixedAmount(),
            DepositMode::PERCENT => $totalCzk === null ? null : (float) $totalCzk * $this->percent() / 100,
        };

        if ($amount === null || $amount <= 0.0) {
            return null;
        }
        if ($totalCzk !== null && $amount > (float) $totalCzk) {
            $amount = (float) $totalCzk;
        }

        return self::format($amount);
    }

    /**
     * Hodnoty pro předvyplnění formuláře. `value` nese buď částku (fixní), nebo
     * procento — podle režimu.
     *
     * @return array{mode: DepositMode, value: string, dueDays: int}
     */
    public function currentValues(): array
    {
        return [
            'mode' => $this->mode(),
            'value' => $this->rawValue(),
            'dueDays' => $this->dueDays(),
        ];
    }

    private function rawValue(): string
    {
        return (string) $this->settings->getString(self::KEY_VALUE);
    }

    private function fixedAmount(): float
    {
        return (float) str_replace(',', '.', $this->rawValue());
    }

    private function percent(): float
    {
        return (float) str_replace(',', '.', (string) $this->settings->getString(self::KEY_VALUE));
    }

    private static function format(float $amount): string
    {
        return Money::normalize($amount);
    }
}
