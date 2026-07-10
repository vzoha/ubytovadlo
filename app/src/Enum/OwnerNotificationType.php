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
 * Typy e-mailových notifikací ubytovateli. Každý typ má svůj režim doručení
 * (okamžitě / denní souhrn / vypnuto), který si provozovatel nastaví v UI.
 * Hodnota enumu je zároveň sufix Setting klíče (`notifications.owner.mode.<value>`).
 */
enum OwnerNotificationType: string
{
    case NEW_RESERVATION = 'new_reservation';
    case PAYMENT_RECEIVED = 'payment_received';
    case CHECKIN_COMPLETED = 'checkin_completed';
    case GUEST_MESSAGE_FAILED = 'guest_message_failed';
    case VAT_REMINDER = 'vat_reminder';
    case UBYPORT_DUE = 'ubyport_due';
    case IDENTIFIED_PERSON_ONSET = 'identified_person_onset';

    /** Krátký název typu pro UI nastavení. */
    public function label(): string
    {
        return match ($this) {
            self::NEW_RESERVATION => 'Nová rezervace',
            self::PAYMENT_RECEIVED => 'Přišla platba',
            self::CHECKIN_COMPLETED => 'Host dokončil check-in',
            self::GUEST_MESSAGE_FAILED => 'Selhalo odeslání zprávy hostovi',
            self::VAT_REMINDER => 'Připomínka DPH přiznání',
            self::UBYPORT_DUE => 'Cizinec k nahlášení na Ubyport',
            self::IDENTIFIED_PERSON_ONSET => 'Vznik identifikované osoby',
        };
    }

    /** Vysvětlení do formuláře nastavení. */
    public function description(): string
    {
        return match ($this) {
            self::NEW_RESERVATION => 'Přišla nová rezervace z webu nebo z OTA (Booking/Airbnb).',
            self::PAYMENT_RECEIVED => 'Dorazila a spárovala se platba k rezervaci.',
            self::CHECKIN_COMPLETED => 'Host vyplnil online check-in a nahrál doklady.',
            self::GUEST_MESSAGE_FAILED => 'Automatickou zprávu hostovi se nepodařilo odeslat — je třeba zásah.',
            self::VAT_REMINDER => 'Blíží se termín DPH přiznání za měsíc s přijatou provizí (do 25.).',
            self::UBYPORT_DUE => 'Zahraniční host čeká na nahlášení na Ubyport (lhůta 3 dny od příjezdu).',
            self::IDENTIFIED_PERSON_ONSET => 'První přijatá provize z OTA založila registrační povinnost identifikované osoby (do 15 dnů).',
        };
    }

    /** Výchozí režim, dokud si provozovatel nenastaví vlastní. */
    public function defaultMode(): OwnerNotificationMode
    {
        return match ($this) {
            self::CHECKIN_COMPLETED => OwnerNotificationMode::DIGEST,
            default => OwnerNotificationMode::IMMEDIATE,
        };
    }
}
