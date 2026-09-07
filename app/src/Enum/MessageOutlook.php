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
 * Co se s naplánovanou zprávou hostovi stane, až nadejde její čas.
 *
 * Skládá se z režimu šablony (sama / na tlačítko / vypnutá) a z cesty ke hostovi
 * u prodejního kanálu (poštou / do chatu portálu / nikam). Rozhoduje o odeslání
 * ({@see \App\Timeline\GuestMessageDispatcher}) a zároveň se ukazuje na časové
 * ose, ať ubytovatel ví, jestli akce čeká na něj.
 */
enum MessageOutlook: string
{
    case AUTO_EMAIL = 'auto_email';
    case MANUAL_EMAIL = 'manual_email';
    case CHAT = 'chat';
    case TEMPLATE_OFF = 'template_off';
    case CHANNEL_SILENT = 'channel_silent';

    /** Krátký štítek na osu. */
    public function label(): string
    {
        return match ($this) {
            self::AUTO_EMAIL => 'odejde sama',
            self::MANUAL_EMAIL => 'čeká na odeslání',
            self::CHAT => 'do chatu portálu',
            self::TEMPLATE_OFF => 'vypnutá',
            self::CHANNEL_SILENT => 'kanál nepíše',
        };
    }

    /** Celá věta do nápovědy — proč to tak dopadne a kde se to mění. */
    public function hint(): string
    {
        return match ($this) {
            self::AUTO_EMAIL => 'V naplánovaný čas se e-mail hostovi odešle sám.',
            self::MANUAL_EMAIL => 'Šablona je v Nastavení → Zprávy nastavená na ruční odeslání — zpráva počká na časové ose, dokud ji neodešlete tlačítkem.',
            self::CHAT => 'Kanál vede zprávy do chatu portálu — text zkopírujete a vložíte tam, e-mail neodejde.',
            self::TEMPLATE_OFF => 'Šablona je v Nastavení → Zprávy vypnutá — až nadejde čas, akce se zavře bez odeslání.',
            self::CHANNEL_SILENT => 'Prodejní kanál má v Nastavení → Kanály odesílání zpráv vypnuté.',
        };
    }

    /** Čeká na ubytovatele — sama se nikdy neodešle. */
    public function needsOwner(): bool
    {
        return $this === self::MANUAL_EMAIL || $this === self::CHAT;
    }

    /** Text vkládá ubytovatel do chatu portálu, ne do e-mailu. */
    public function isChat(): bool
    {
        return $this === self::CHAT;
    }

    /** Hostovi nedorazí nic — nastavení odesílání to zastavilo. */
    public function isBlocked(): bool
    {
        return $this === self::TEMPLATE_OFF || $this === self::CHANNEL_SILENT;
    }
}
