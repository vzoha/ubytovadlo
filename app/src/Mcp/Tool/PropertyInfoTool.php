<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Config\InstanceSettings;
use App\Mcp\McpTool;
use App\Repository\SettingRepository;
use App\Ubyport\AccommodationProfileWriter;
use Psr\Clock\ClockInterface;

/**
 * Co o ubytování ví jen majitel: ceník, příjezd, klíče, parkování, zvířata.
 * Text píše majitel v nastavení AI asistenta; bez něj model nemá z čeho
 * odpovídat a má si to přiznat, ne vymýšlet.
 */
final class PropertyInfoTool implements McpTool
{
    public const SETTING_KEY = 'ai.property_info';

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly AccommodationProfileWriter $profile,
        private readonly InstanceSettings $instance,
        private readonly ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return 'get_property_info';
    }

    public function description(): string
    {
        return 'Informace o ubytování od majitele (ceník, příjezd a klíče, parkování, zvířata, okolí, '
            . 'pravidla), název a adresa objektu a dnešní datum. Načti před psaním každé odpovědi. '
            . 'Co tu není, nevymýšlej — nech v odpovědi místo k doplnění ve tvaru [Sem napište…].';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function call(array $arguments): array
    {
        $profile = $this->profile->current();
        $info = trim((string) $this->settings->getString(self::SETTING_KEY, ''));

        return [
            'today' => $this->clock->now()->format('Y-m-d'),
            'brand' => $this->instance->brandName(),
            'propertyName' => $profile->getNazev() !== '' ? $profile->getNazev() : null,
            'address' => $profile->getAddress()->isEmpty() ? null : $profile->getAddress()->format(),
            'ownerInfo' => $info !== '' ? $info : null,
            'writingStyle' => 'Vykej malým písmem (vás, vám). Konkrétní údaje místo obecných frází, '
                . 'rozsah dat pomlčkou (12.–19. 7.), žádné vykřičníky. Poslední odstavec: věta k tématu '
                . 'a nabídka ozvat se. Hostovi odpovídej v jazyce, kterým píše.',
        ];
    }
}
