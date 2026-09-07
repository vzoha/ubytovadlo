<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Config;

use App\Enum\Channel;
use App\Enum\GuestMessaging;
use App\Repository\SettingRepository;

/**
 * Kudy z každého prodejního kanálu vedou zprávy hostům. Dokud si to ubytovatel
 * nenastaví, platí výchozí volba kanálu.
 */
final class ChannelMessagingSettings
{
    public function __construct(
        private readonly SettingRepository $settings,
    ) {
    }

    public function for(Channel $channel): GuestMessaging
    {
        $stored = GuestMessaging::tryFrom($this->settings->getString(self::key($channel), ''));
        if ($stored === null || !in_array($stored, $channel->messagingOptions(), true)) {
            return $channel->defaultMessaging();
        }

        return $stored;
    }

    public function set(Channel $channel, GuestMessaging $messaging): void
    {
        $this->settings->set(
            self::key($channel),
            $messaging->value,
            sprintf('%s: kudy vedou zprávy hostům.', $channel->label()),
        );
    }

    /**
     * Uloží volby z formuláře; hodnotu, která u kanálu nedává smysl, přeskočí.
     *
     * @param array<string, string> $choices podle hodnoty kanálu
     */
    public function saveChoices(array $choices): void
    {
        foreach (Channel::cases() as $channel) {
            $choice = GuestMessaging::tryFrom($choices[$channel->value] ?? '');
            if ($choice !== null && in_array($choice, $channel->messagingOptions(), true)) {
                $this->set($channel, $choice);
            }
        }
    }

    /**
     * Podklad pro nastavení: každý kanál se svou volbou, nabídkou a poznámkou,
     * pod svou hodnotou — kartu napojení zajímá právě jeho kanál.
     *
     * @return array<string, array{channel: Channel, selected: GuestMessaging, options: list<GuestMessaging>, hint: string|null}>
     */
    public function overview(): array
    {
        $rows = [];
        foreach (Channel::cases() as $channel) {
            $rows[$channel->value] = [
                'channel' => $channel,
                'selected' => $this->for($channel),
                'options' => $channel->messagingOptions(),
                'hint' => $channel->messagingHint(),
            ];
        }

        return $rows;
    }

    private static function key(Channel $channel): string
    {
        return sprintf('channel.%s.messaging', $channel->value);
    }
}
