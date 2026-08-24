<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Formatting;

use App\Enum\TaskIntervalUnit;

/**
 * České vyjádření lhůty opakování — „1× ročně", „1× za 5 let", „1× za 14 dní".
 * Jedno místo pro seznam termínů, katalog i e-mailovou připomínku.
 */
final class TaskInterval
{
    /** @var array<string, array{0: string, 1: string}> jednotka → tvar pro 2–4 a pro 5 a víc */
    private const FORMS = [
        'day' => ['dny', 'dní'],
        'month' => ['měsíce', 'měsíců'],
        'year' => ['roky', 'let'],
    ];

    public static function label(?int $value, ?TaskIntervalUnit $unit): string
    {
        if ($value === null || $unit === null || $value < 1) {
            return 'jednorázově';
        }
        if ($value === 1) {
            return match ($unit) {
                TaskIntervalUnit::DAY => 'denně',
                TaskIntervalUnit::MONTH => '1× měsíčně',
                TaskIntervalUnit::YEAR => '1× ročně',
            };
        }

        return sprintf('1× za %d %s', $value, self::plural($value, $unit));
    }

    private static function plural(int $value, TaskIntervalUnit $unit): string
    {
        $forms = self::FORMS[$unit->value];

        return $value < 5 ? $forms[0] : $forms[1];
    }
}
