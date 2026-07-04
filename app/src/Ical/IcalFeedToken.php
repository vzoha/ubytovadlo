<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Ical;

use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Instance-wide token neuhodnutelné části URL veřejného iCal feedu obsazenosti.
 * Jeden token pro celou instanci (feed je jeden), uložený v Setting.
 * 64 hex znaků = 256 bitů entropie.
 */
final class IcalFeedToken
{
    public const SETTING_KEY = 'ical.feed_token';

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Vrátí token; při prvním použití ho vygeneruje a uloží. */
    public function getOrCreate(): string
    {
        $token = $this->stored();
        if ($token !== null) {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        $this->settings->set(self::SETTING_KEY, $token, 'Veřejný iCal feed obsazenosti.');
        $this->em->flush();

        return $token;
    }

    /** Uložený token, nebo null když ještě nebyl vygenerován. */
    public function stored(): ?string
    {
        return $this->settings->getString(self::SETTING_KEY);
    }

    /** Porovnání v konstantním čase — token je jediné tajemství feedu. */
    public function matches(string $candidate): bool
    {
        $token = $this->stored();

        return $token !== null && hash_equals($token, $candidate);
    }
}
