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
 * Druh naplánované budoucí akce na časové ose rezervace.
 *
 * Zprávy hostům (PRE_ARRIVAL_MESSAGE, POST_STAY_MESSAGE, CUSTOM_MESSAGE) se v MVP
 * jen plánují a zobrazují — vlastní odeslání e-mailů je roadmap bod „Zprávy hostům".
 * Připomínky (BALANCE_REMINDER, ISSUE_FINAL_INVOICE, UBYPORT_EXPORT, CUSTOM_REMINDER)
 * se self-resolvují podle stavu rezervace (viz ReservationActionExecutor), jinak nagují.
 */
enum ActionType: string
{
    case RESERVATION_REQUEST_MESSAGE = 'reservation_request_message';
    case PRE_ARRIVAL_MESSAGE = 'pre_arrival_message';
    case POST_STAY_MESSAGE = 'post_stay_message';
    case ISSUE_FINAL_INVOICE = 'issue_final_invoice';
    case BALANCE_REMINDER = 'balance_reminder';
    case UBYPORT_EXPORT = 'ubyport_export';
    case CUSTOM_REMINDER = 'custom_reminder';
    case CUSTOM_MESSAGE = 'custom_message';

    public function label(): string
    {
        return match ($this) {
            self::RESERVATION_REQUEST_MESSAGE => 'Žádost o zálohu',
            self::PRE_ARRIVAL_MESSAGE => 'Zpráva před příjezdem',
            self::POST_STAY_MESSAGE => 'Zpráva po pobytu',
            self::ISSUE_FINAL_INVOICE => 'Vystavit doplatkovou fakturu',
            self::BALANCE_REMINDER => 'Připomínka doplatku',
            self::UBYPORT_EXPORT => 'Nahlásit hosta na Ubyport',
            self::CUSTOM_REMINDER => 'Připomínka',
            self::CUSTOM_MESSAGE => 'Zpráva hostovi',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::RESERVATION_REQUEST_MESSAGE, self::PRE_ARRIVAL_MESSAGE, self::POST_STAY_MESSAGE, self::CUSTOM_MESSAGE => '✉️',
            self::ISSUE_FINAL_INVOICE => '🧾',
            self::BALANCE_REMINDER => '💰',
            self::UBYPORT_EXPORT => '🛂',
            self::CUSTOM_REMINDER => '⏰',
        };
    }

    /** Zpráva hostovi — v MVP se neodesílá, jen plánuje (čeká na roadmap „Zprávy hostům"). */
    public function isGuestMessage(): bool
    {
        return match ($this) {
            self::RESERVATION_REQUEST_MESSAGE, self::PRE_ARRIVAL_MESSAGE, self::POST_STAY_MESSAGE, self::CUSTOM_MESSAGE => true,
            default => false,
        };
    }

    /** Akce, kterou lze z osy ručně odeslat hostovi e-mailem (má svou šablonu). */
    public function sendsGuestMessage(): bool
    {
        return $this->isGuestMessage() || $this === self::BALANCE_REMINDER;
    }
}
