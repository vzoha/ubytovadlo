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
 * Hodnoty se čtou z DB (setting), nastavují se v UI (/nastaveni/pripojeni).
 */
final class MotoPressSettings
{
    public const KEY_PET = 'motopress.pet_service_ids';
    public const KEY_BABY_COT = 'motopress.baby_cot_service_ids';
    public const KEY_PUSH = 'motopress.push_payments';

    public function __construct(
        private readonly SettingRepository $settings,
    ) {
    }

    /** @return list<int> */
    public function petServiceIds(): array
    {
        return $this->ids(self::KEY_PET);
    }

    /** @return list<int> */
    public function babyCotServiceIds(): array
    {
        return $this->ids(self::KEY_BABY_COT);
    }

    public function pushPayments(): bool
    {
        return $this->settings->getString(self::KEY_PUSH) === '1';
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

    /** @return list<int> */
    private function ids(string $key): array
    {
        return self::parseIds((string) $this->settings->getString($key));
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
