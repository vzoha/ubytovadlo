<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mcp;

use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Přístupový token AI asistenta. Ukáže se jen jednou při vydání, v DB leží jen
 * jeho SHA-256 — kdo přečte zálohu databáze, k datům hostů se přes MCP nedostane.
 * Nový token starý zneplatní; zrušení přístup vypne.
 */
final class McpAccessToken
{
    public const SETTING_KEY = 'mcp.token_hash';
    private const PREFIX = 'ubyt_';

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Vydá nový token a vrátí ho v čitelné podobě — jediná chvíle, kdy je k vidění. */
    public function issue(): string
    {
        $token = self::PREFIX . bin2hex(random_bytes(32));
        $this->settings->set(self::SETTING_KEY, hash('sha256', $token), 'Otisk přístupového tokenu AI asistenta (MCP).');
        $this->em->flush();

        return $token;
    }

    public function revoke(): void
    {
        $this->settings->set(self::SETTING_KEY, '');
        $this->em->flush();
    }

    public function isIssued(): bool
    {
        return $this->storedHash() !== '';
    }

    /** Porovnání v konstantním čase. */
    public function matches(#[\SensitiveParameter] string $candidate): bool
    {
        $hash = $this->storedHash();

        return $hash !== '' && $candidate !== '' && hash_equals($hash, hash('sha256', $candidate));
    }

    private function storedHash(): string
    {
        return (string) $this->settings->getString(self::SETTING_KEY, '');
    }
}
