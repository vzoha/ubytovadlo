<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\MotoPress;

use App\Repository\SettingRepository;

/**
 * Chování napojení na MotoPress, které se liší instanci od instance:
 *  - ID služeb značících „host se psem" a „host chce dětskou postýlku",
 *  - zda posílat potvrzené platby zpět do MotoPressu.
 *
 * Zapnutí/vypnutí importu drží konektor (App\Connector\ConnectorManager).
 *
 * Přednost má hodnota z DB (setting), fallback jsou hodnoty z env
 * (MOTOPRESS_PET_SERVICE_IDS / MOTOPRESS_BABY_COT_SERVICE_IDS /
 * MOTOPRESS_PUSH_PAYMENTS). Nastavuje se v UI (/nastaveni/pripojeni).
 */
final class MotoPressSettings
{
    public const KEY_PET = 'motopress.pet_service_ids';
    public const KEY_BABY_COT = 'motopress.baby_cot_service_ids';
    public const KEY_PUSH = 'motopress.push_payments';

    /**
     * @param array<int|string, scalar> $petFallback
     * @param array<int|string, scalar> $babyCotFallback
     */
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly array $petFallback = [],
        private readonly array $babyCotFallback = [],
        private readonly bool $pushFallback = false,
    ) {
    }

    /** @return list<int> */
    public function petServiceIds(): array
    {
        return $this->ids(self::KEY_PET, $this->petFallback);
    }

    /** @return list<int> */
    public function babyCotServiceIds(): array
    {
        return $this->ids(self::KEY_BABY_COT, $this->babyCotFallback);
    }

    public function pushPayments(): bool
    {
        $stored = $this->settings->getString(self::KEY_PUSH);

        return $stored === null ? $this->pushFallback : $stored === '1';
    }

    /** Mapper drží normalizovaná ID → sestavíme ho z aktuálního nastavení. */
    public function createMapper(): MotoPressBookingMapper
    {
        return new MotoPressBookingMapper($this->petServiceIds(), $this->babyCotServiceIds());
    }

    /**
     * Hodnoty pro předvyplnění formuláře (ID jako čárkami oddělený seznam).
     *
     * @return array{petServiceIds: string, babyCotServiceIds: string, pushPayments: bool}
     */
    public function currentValues(): array
    {
        return [
            'petServiceIds' => implode(', ', $this->petServiceIds()),
            'babyCotServiceIds' => implode(', ', $this->babyCotServiceIds()),
            'pushPayments' => $this->pushPayments(),
        ];
    }

    /**
     * @param array<int|string, scalar> $fallback
     *
     * @return list<int>
     */
    private function ids(string $key, array $fallback): array
    {
        $stored = $this->settings->getString($key);

        // Není v DB → env fallback. Prázdný řetězec (uživatel vymazal) = žádná ID.
        return $stored === null ? self::normalize($fallback) : self::parseIds($stored);
    }

    /**
     * @param iterable<int|string, scalar> $raw
     *
     * @return list<int>
     */
    private static function normalize(iterable $raw): array
    {
        $ids = [];
        foreach ($raw as $value) {
            $int = (int) $value;
            if ($int > 0) {
                $ids[] = $int;
            }
        }

        return $ids;
    }

    /** @return list<int> */
    public static function parseIds(string $csv): array
    {
        return self::normalize(array_map('trim', explode(',', $csv)));
    }
}
