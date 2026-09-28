<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Profit;

use App\Entity\Reservation;
use App\Enum\Channel;

/**
 * Zdroj rezervace („odkud nás host zná") pro souhrn ekonomiky. Volný text
 * sjednotí na klíč bez ohledu na velikost písmen a interpunkci, popisek
 * začíná velkým písmenem. Hodnota odpovídající kanálu („Booking",
 * „E-chalupy") dostane popisek kanálu.
 *
 * Bez vyplněného zdroje je u portálu (Booking, Airbnb, eChalupy, CS chalupy)
 * zdrojem portál sám; web a přímá rezervace zůstávají neuvedené.
 */
final class AcquisitionSourceResolver
{
    public const UNKNOWN_KEY = '';
    public const UNKNOWN_LABEL = 'Neuvedeno';

    private const UNKNOWN_VALUES = ['neznámo', 'neznamo', 'nevím', 'nevim'];

    /**
     * @return array{key: string, label: string}
     */
    public static function resolve(Reservation $reservation): array
    {
        $raw = trim((string) $reservation->getAcquisitionSource());
        $key = self::normalize($raw);

        if ($key === self::UNKNOWN_KEY || \in_array($key, self::UNKNOWN_VALUES, true)) {
            $channel = $reservation->getChannel();
            if ($channel === Channel::WEB || $channel === Channel::DIRECT) {
                return ['key' => self::UNKNOWN_KEY, 'label' => self::UNKNOWN_LABEL];
            }

            return ['key' => self::normalize($channel->value), 'label' => $channel->label()];
        }

        $channel = self::matchChannel($key);
        if ($channel !== null) {
            return ['key' => self::normalize($channel->value), 'label' => $channel->label()];
        }

        return ['key' => $key, 'label' => mb_ucfirst($raw)];
    }

    private static function matchChannel(string $key): ?Channel
    {
        foreach (Channel::cases() as $channel) {
            if ($key === self::normalize($channel->value) || $key === self::normalize($channel->label())) {
                return $channel;
            }
        }

        return null;
    }

    private static function normalize(string $value): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value));
    }
}
