<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Katalog check-inu musí mít ve všech jazycích stejnou sadu klíčů — jinak
 * některý host uvidí místo textu syrový překladový klíč.
 */
final class CheckinCatalogTest extends TestCase
{
    private const DIR = __DIR__ . '/../../translations/';
    private const REFERENCE = 'cs';
    private const TRANSLATIONS = ['en', 'de', 'fr', 'it', 'pl'];

    public function testAllLocalesHaveSameKeysAsCzech(): void
    {
        $reference = $this->keys(self::REFERENCE);
        self::assertNotEmpty($reference);

        foreach (self::TRANSLATIONS as $locale) {
            $keys = $this->keys($locale);
            self::assertSame([], array_values(array_diff($reference, $keys)), "V katalogu '$locale' chybí klíče");
            self::assertSame([], array_values(array_diff($keys, $reference)), "Katalog '$locale' má klíče navíc");
        }
    }

    /**
     * @return list<string>
     */
    private function keys(string $locale): array
    {
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile(self::DIR . "checkin.$locale.yaml");
        $keys = $this->flatten($parsed);
        sort($keys);

        return $keys;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $full = $prefix === '' ? (string) $key : "$prefix.$key";
            if (\is_array($value)) {
                $keys = array_merge($keys, $this->flatten($value, $full));
            } else {
                $keys[] = $full;
            }
        }

        return $keys;
    }
}
