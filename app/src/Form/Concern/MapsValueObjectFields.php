<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form\Concern;

/**
 * Sdílené drobnosti formulářových typů nad neměnnými value objekty: skládání
 * options jednotlivých políček (volající je přizpůsobí přes `field_options`)
 * a čtení odeslané hodnoty jako řetězce.
 */
trait MapsValueObjectFields
{
    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function fieldOptions(string $field, array $defaults, array $options): array
    {
        /** @var array<string, array<string, mixed>> $overrides */
        $overrides = $options['field_options'];

        return array_replace($defaults, $overrides[$field] ?? []);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
