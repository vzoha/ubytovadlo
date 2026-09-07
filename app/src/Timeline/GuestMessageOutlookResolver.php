<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Timeline;

use App\Entity\ReservationAction;
use App\Enum\GuestMessaging;
use App\Enum\MessageKind;
use App\Enum\MessageOutlook;
use App\Enum\SendMode;
use App\Mail\GuestMessageDelivery;
use App\Mail\MessageTemplateProvider;

/**
 * Jak dopadne naplánovaná zpráva hostovi. Jediné místo, kde se skládá režim
 * šablony s cestou ke hostovi — čte z něj odesílání i časová osa, takže štítek
 * na ose nemůže slibovat něco jiného, než co pak cron udělá.
 */
final class GuestMessageOutlookResolver
{
    public function __construct(
        private readonly GuestMessageDelivery $delivery,
        private readonly MessageTemplateProvider $templates,
    ) {
    }

    /** Vrací null u akce, která zprávu hostovi neposílá (např. hlídaný termín). */
    public function forAction(ReservationAction $action): ?MessageOutlook
    {
        $kind = MessageKind::fromActionType($action->getType());
        if ($kind === null) {
            return null;
        }

        // Vlastní zpráva vzniká ručně i s textem, režim šablony na ni neplatí.
        $mode = $kind === MessageKind::CUSTOM ? SendMode::AUTO : $this->templates->for($kind)->getMode();
        if ($mode === SendMode::OFF) {
            return MessageOutlook::TEMPLATE_OFF;
        }

        return match ($this->delivery->route($action->getReservation())) {
            GuestMessaging::NONE => MessageOutlook::CHANNEL_SILENT,
            GuestMessaging::CHAT => MessageOutlook::CHAT,
            GuestMessaging::EMAIL => $mode === SendMode::AUTO ? MessageOutlook::AUTO_EMAIL : MessageOutlook::MANUAL_EMAIL,
        };
    }
}
