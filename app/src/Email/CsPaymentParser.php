<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email;

use App\Email\Dto\CsPaymentData;
use App\Formatting\Money;

/**
 * Parsuje e-mailovou notifikaci České spořitelny "Přišla platba". Ta nese vše pro
 * spárování příchozí platby s rezervací — především variabilní symbol (= kód
 * rezervace / MotoPress booking ID), částku a účet protistrany. Díky tomu nepotřebujeme
 * bankovní API ani import výpisu.
 *
 * Pravost ověřuje poštovní server (DMARC p=reject na csas.cz); parser kotví jen na
 * odesílací doménu, stejně jako ostatní e-mailové parsery v projektu.
 *
 * Vázané na konkrétní HTML šablonu ČS — pro jiné banky vznikne samostatný parser.
 */
class CsPaymentParser
{
    private const FROM_DOMAIN = 'csas.cz';
    private const SUBJECT_PATTERN = '/Při[šs]la\s+platba/u';

    public function supports(EmailMessage $email): bool
    {
        if ($email->fromAddress !== null
            && stripos($email->fromAddress, self::FROM_DOMAIN) !== false) {
            return (bool) preg_match(self::SUBJECT_PATTERN, $email->subject);
        }

        return false;
    }

    public function parse(EmailMessage $email): CsPaymentData
    {
        if (!$this->supports($email)) {
            throw new \InvalidArgumentException('E-mail nevypadá jako notifikace ČS "Přišla platba".');
        }

        $text = $this->normalize($email->textBody);

        return new CsPaymentData(
            incoming: $this->isIncoming($text),
            amount: $this->extractAmount($text),
            currency: $this->extractCurrency($text),
            variableSymbol: $this->extractSymbol($text, 'Variabilní'),
            constantSymbol: $this->extractSymbol($text, 'Konstantní'),
            counterpartyAccount: $this->extractCounterpartyAccount($text),
            receivedAt: $email->date,
        );
    }

    /**
     * Předmět "Přišla platba" znamená příchozí platbu. Směr přesto čteme z těla —
     * a za odchozí ho označíme jen při explicitní shodě, jinak důvěřujeme předmětu.
     */
    private function isIncoming(string $text): bool
    {
        if (preg_match('/Směr platby:\s*(\p{L}+)/u', $text, $m)) {
            return mb_strtolower($m[1]) !== 'odchozí';
        }

        return true;
    }

    private function extractAmount(string $text): string
    {
        // "Částka v měně účtu: 1 000,00 Kč" je částka připsaná na náš účet.
        if (preg_match('/Částka v měně účtu:\s*([\d\s\x{00a0}]+,\d{2})/u', $text, $m)) {
            return $this->parseAmount($m[1]);
        }
        // Fallback na úvodní větu "právě dorazila platba ve výši 1 000,00 Kč".
        if (preg_match('/dorazila platba ve výši\s+([\d\s\x{00a0}]+,\d{2})/u', $text, $m)) {
            return $this->parseAmount($m[1]);
        }
        throw new \RuntimeException('Nelze vyčíst částku z notifikace ČS.');
    }

    private function extractCurrency(string $text): string
    {
        if (preg_match('/Částka v měně účtu:[^\n]*?(Kč|EUR|USD|€)/u', $text, $m)) {
            return $this->normalizeCurrency($m[1]);
        }

        return 'CZK';
    }

    private function extractSymbol(string $text, string $label): ?string
    {
        // VS/KS vracíme přesně jak je v e-mailu (bez ořezu úvodních nul) — párování
        // je textová shoda a "0042" se nesmí změnit na "42".
        if (preg_match('/' . preg_quote($label, '/') . ' symbol:\s*(\d+)/u', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractCounterpartyAccount(string $text): ?string
    {
        if (preg_match('#Číslo účtu protistrany:\s*([\d-]+/\d{4})#u', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    private function normalizeCurrency(string $raw): string
    {
        return match ($raw) {
            'Kč' => 'CZK',
            '€' => 'EUR',
            default => $raw,
        };
    }

    private function parseAmount(string $raw): string
    {
        $clean = preg_replace('/[\s\x{00a0}]+/u', '', $raw) ?? $raw;
        $clean = str_replace(',', '.', $clean);

        return Money::normalize((float) $clean);
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\xc2\xa0", "\xe2\x80\xaf"], ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
