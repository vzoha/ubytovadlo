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
 * Druh e-mailové zprávy hostovi. Každý druh má vlastní editovatelnou šablonu
 * (předmět + tělo) a mapuje se na okamžik odeslání:
 *  - PRE_ARRIVAL / POST_STAY / CUSTOM / BALANCE_REMINDER → naplánovaná akce na ose,
 *  - INVOICE → odeslání faktury v příloze (ručně tlačítkem nebo po vystavení).
 */
enum MessageKind: string
{
    case RESERVATION_REQUEST = 'reservation_request';
    case RESERVATION_CONFIRMED = 'reservation_confirmed';
    case PRE_ARRIVAL = 'pre_arrival';
    case POST_STAY = 'post_stay';
    case BALANCE_REMINDER = 'balance_reminder';
    case INVOICE = 'invoice';
    case CUSTOM = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::RESERVATION_REQUEST => 'Žádost o zálohu',
            self::RESERVATION_CONFIRMED => 'Potvrzení rezervace',
            self::PRE_ARRIVAL => 'Před příjezdem',
            self::POST_STAY => 'Po pobytu',
            self::BALANCE_REMINDER => 'Připomínka doplatku',
            self::INVOICE => 'Faktura e-mailem',
            self::CUSTOM => 'Vlastní zpráva',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RESERVATION_REQUEST => 'Odejde hned po objednávce — poděkování a pokyny k platbě zálohy s QR kódem.',
            self::RESERVATION_CONFIRMED => 'Odejde po zaplacení zálohy (nebo ručně) — potvrzení, že rezervace platí.',
            self::PRE_ARRIVAL => 'Odejde pár dní před příjezdem — instrukce, příjezd, kontakt.',
            self::POST_STAY => 'Odejde den po odjezdu — poděkování, žádost o recenzi.',
            self::BALANCE_REMINDER => 'Připomene hostovi doplatek, dokud není uhrazen.',
            self::INVOICE => 'Průvodní text e-mailu, ke kterému se přiloží faktura v PDF.',
            self::CUSTOM => 'Volná zpráva, kterou pošleš hostovi ručně z časové osy.',
        };
    }

    /** Mapování naplánované akce na druh zprávy (null = akce není zpráva hostovi). */
    public static function fromActionType(ActionType $type): ?self
    {
        return match ($type) {
            ActionType::RESERVATION_REQUEST_MESSAGE => self::RESERVATION_REQUEST,
            ActionType::PRE_ARRIVAL_MESSAGE => self::PRE_ARRIVAL,
            ActionType::POST_STAY_MESSAGE => self::POST_STAY,
            ActionType::CUSTOM_MESSAGE => self::CUSTOM,
            ActionType::BALANCE_REMINDER => self::BALANCE_REMINDER,
            default => null,
        };
    }
}
