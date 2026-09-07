<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Config\ChannelMessagingSettings;
use App\Entity\Reservation;
use App\Enum\GuestMessaging;

/**
 * Kudy se k hostovi dostane zpráva. Rozhoduje volba u prodejního kanálu; při
 * volbě „E-mailem" u portálu s chatem drží komunikaci chat, dokud adresu hosta
 * neznáme.
 */
class GuestMessageDelivery
{
    public function __construct(
        private readonly ChannelMessagingSettings $settings,
    ) {
    }

    public function route(Reservation $reservation): GuestMessaging
    {
        $channel = $reservation->getChannel();
        $setting = $this->settings->for($channel);

        if ($setting !== GuestMessaging::EMAIL) {
            return $setting;
        }

        if ($reservation->getGuestContact()->hasEmail() || !$channel->hasGuestChat()) {
            return GuestMessaging::EMAIL;
        }

        return GuestMessaging::CHAT;
    }

    public function byChat(Reservation $reservation): bool
    {
        return $this->route($reservation) === GuestMessaging::CHAT;
    }

    /** Kanál, ze kterého zprávy hostům neposíláme, je ani neplánuje. */
    public function plansMessages(Reservation $reservation): bool
    {
        return $this->settings->for($reservation->getChannel()) !== GuestMessaging::NONE;
    }
}
