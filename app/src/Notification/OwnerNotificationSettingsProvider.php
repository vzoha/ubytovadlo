<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Notification;

use App\Enum\OwnerNotificationMode;
use App\Enum\OwnerNotificationType;
use App\Repository\SettingRepository;

/**
 * Nastavení notifikací ubytovateli z DB (tabulka setting, klíče `notifications.owner.*`).
 * Provozovatel si zvolí jednu adresu příjemce a per-typ režim doručení.
 */
final class OwnerNotificationSettingsProvider
{
    public const RECIPIENT = 'notifications.owner.email';
    private const MODE_PREFIX = 'notifications.owner.mode.';

    public function __construct(
        private readonly SettingRepository $settings,
    ) {
    }

    /** Adresa příjemce notifikací, nebo null když není nastavena. */
    public function recipient(): ?string
    {
        $value = trim((string) $this->settings->getString(self::RECIPIENT, ''));

        return $value !== '' ? $value : null;
    }

    public function modeFor(OwnerNotificationType $type): OwnerNotificationMode
    {
        return OwnerNotificationMode::fromValue(
            $this->settings->getString(self::MODE_PREFIX . $type->value),
            $type->defaultMode(),
        );
    }

    public static function modeKey(OwnerNotificationType $type): string
    {
        return self::MODE_PREFIX . $type->value;
    }

    /**
     * Aktuální hodnoty pro předvyplnění formuláře nastavení.
     *
     * @return array{email: string, modes: array<string, OwnerNotificationMode>}
     */
    public function currentValues(): array
    {
        $modes = [];
        foreach (OwnerNotificationType::cases() as $type) {
            $modes[$type->value] = $this->modeFor($type);
        }

        return [
            'email' => $this->recipient() ?? '',
            'modes' => $modes,
        ];
    }
}
