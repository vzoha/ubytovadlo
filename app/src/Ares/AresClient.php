<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Ares;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Klient pro ARES (Administrativní registr ekonomických subjektů, zdarma, bez
 * autentizace). Z IČO doplní název firmy, sídlo a případně DIČ pro fakturační
 * formulář. Při jakékoli chybě (neexistující IČO, výpadek) vrací null — doplnění
 * je vždy jen pohodlí, nikdy blokující.
 */
class AresClient
{
    private const BASE_URL = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function lookup(string $ico): ?AresCompany
    {
        $ico = preg_replace('/\D/', '', $ico) ?? '';
        if (\strlen($ico) < 1 || \strlen($ico) > 8) {
            return null;
        }
        $ico = str_pad($ico, 8, '0', \STR_PAD_LEFT);

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . $ico, [
                'headers' => ['Accept' => 'application/json'],
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('ARES lookup failed', ['ico' => $ico, 'error' => $e->getMessage()]);

            return null;
        }

        if (!isset($data['ico'])) {
            return null;
        }

        $sidlo = $data['sidlo'] ?? [];

        return new AresCompany(
            ico: (string) $data['ico'],
            companyName: $data['obchodniJmeno'] ?? null,
            street: $this->composeStreet($sidlo),
            city: $sidlo['nazevObce'] ?? null,
            zip: $this->formatZip($sidlo['psc'] ?? null),
            country: $sidlo['kodStatu'] ?? 'CZ',
            dic: $data['dic'] ?? null,
        );
    }

    /** @param array<string, mixed> $sidlo */
    private function composeStreet(array $sidlo): ?string
    {
        $house = (string) ($sidlo['cisloDomovni'] ?? '');
        $orient = isset($sidlo['cisloOrientacni']) ? '/' . $sidlo['cisloOrientacni'] . ($sidlo['cisloOrientacniPismeno'] ?? '') : '';
        $number = trim($house . $orient);

        $base = $sidlo['nazevUlice'] ?? $sidlo['nazevCastObce'] ?? $sidlo['nazevObce'] ?? null;
        if ($base === null) {
            // Bez ulice i obce zkusíme aspoň textovou adresu (první část před čárkou).
            $text = $sidlo['textovaAdresa'] ?? null;

            return $text !== null ? trim(explode(',', $text)[0]) : null;
        }

        return $number !== '' ? $base . ' ' . $number : $base;
    }

    private function formatZip(int|string|null $psc): ?string
    {
        if ($psc === null) {
            return null;
        }
        $digits = preg_replace('/\D/', '', (string) $psc) ?? '';
        if (\strlen($digits) !== 5) {
            return $digits !== '' ? $digits : null;
        }

        return substr($digits, 0, 3) . ' ' . substr($digits, 3);
    }
}
