<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

/**
 * Registr přednastavených barevných témat e-mailů + sestavení vlastního tématu
 * z hexů. Provozovatel si v nastavení vybere preset, nebo zvolí `custom` a zadá
 * vlastní primární/akcentní barvu.
 */
final class MailThemes
{
    public const CUSTOM = 'custom';
    public const DEFAULT = 'slate';

    /** @var array<string, array{label: string, primary: string, accent: string}> */
    private const PRESETS = [
        'slate' => ['label' => 'Břidlicová', 'primary' => '#334155', 'accent' => '#0ea5e9'],
        'forest' => ['label' => 'Lesní', 'primary' => '#166534', 'accent' => '#65a30d'],
        'sea' => ['label' => 'Mořská', 'primary' => '#0f766e', 'accent' => '#06b6d4'],
        'wine' => ['label' => 'Vínová', 'primary' => '#9f1239', 'accent' => '#e11d48'],
        'graphite' => ['label' => 'Grafitová', 'primary' => '#1f2937', 'accent' => '#f59e0b'],
    ];

    /**
     * Volby pro výběr v UI (preset klíče + vlastní).
     *
     * @return array<string, string> label => key
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::PRESETS as $key => $preset) {
            $choices[$preset['label']] = $key;
        }
        $choices['Vlastní barvy'] = self::CUSTOM;

        return $choices;
    }

    /**
     * Přednastavená témata jako hodnotové objekty — pro vykreslení barevných
     * vzorků v nastavení.
     *
     * @return list<MailTheme>
     */
    public static function presets(): array
    {
        $presets = [];
        foreach (self::PRESETS as $key => $preset) {
            $presets[] = new MailTheme($key, $preset['label'], $preset['primary'], $preset['accent']);
        }

        return $presets;
    }

    /**
     * Sestaví téma. Pro `custom` použije zadané hexy (s fallbackem na výchozí
     * preset, když chybí), pro neznámý klíč spadne na výchozí preset.
     */
    public static function resolve(string $key, ?string $customPrimary = null, ?string $customAccent = null): MailTheme
    {
        $default = self::PRESETS[self::DEFAULT];

        if ($key === self::CUSTOM) {
            return new MailTheme(
                self::CUSTOM,
                'Vlastní barvy',
                self::normalizeHex($customPrimary) ?? $default['primary'],
                self::normalizeHex($customAccent) ?? $default['accent'],
            );
        }

        $preset = self::PRESETS[$key] ?? $default;

        return new MailTheme($key, $preset['label'], $preset['primary'], $preset['accent']);
    }

    /** Ověří `#rrggbb` (3 i 6 znaků), jinak null. */
    private static function normalizeHex(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1 ? $value : null;
    }
}
