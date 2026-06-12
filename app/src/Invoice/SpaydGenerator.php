<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

/**
 * Generátor SPAYD stringu pro QR Platbu (CZ).
 *
 * Spec: https://qr-platba.cz/pro-vyvojare/specifikace-formatu/
 * Formát: SPD*1.0*ACC:IBAN[+BIC]*AM:1234.56*CC:CZK*X-VS:1234567890*MSG:popis
 *
 * Hodnoty stringy musí být alfanumerické (žádné háčky/čárky) — text se přepíše do ASCII.
 */
class SpaydGenerator
{
    public function generate(
        string $iban,
        string $amount,
        string $currency,
        ?string $variableSymbol = null,
        ?string $message = null,
        ?\DateTimeImmutable $dueDate = null,
    ): string {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
        if ($iban === '') {
            throw new \InvalidArgumentException('IBAN je povinný.');
        }

        $parts = [
            'SPD*1.0',
            'ACC:' . $iban,
            sprintf('AM:%.2F', (float) $amount),
            'CC:' . strtoupper($currency),
        ];

        if ($variableSymbol !== null && $variableSymbol !== '') {
            $parts[] = 'X-VS:' . preg_replace('/\D/', '', $variableSymbol);
        }

        if ($message !== null && $message !== '') {
            $parts[] = 'MSG:' . $this->sanitizeMessage($message);
        }

        if ($dueDate !== null) {
            $parts[] = 'DT:' . $dueDate->format('Ymd');
        }

        return implode('*', $parts);
    }

    /**
     * Z 19-2000145399/0800 udělá CZ6508000000192000145399.
     * Spec: CZkk BBBB PPPP PPCC CCCC CCCC — BBBB=bank, P=prefix (0 if none), C=account number padded.
     */
    public function bankAccountToIban(string $czAccount): string
    {
        if (preg_match('/^(?:(\d{1,6})-)?(\d{1,10})\/(\d{4})$/', trim($czAccount), $m) !== 1) {
            throw new \InvalidArgumentException(sprintf('Neplatný formát čísla účtu: %s', $czAccount));
        }
        $prefix = str_pad($m[1], 6, '0', STR_PAD_LEFT);
        $accountNumber = str_pad($m[2], 10, '0', STR_PAD_LEFT);
        $bankCode = $m[3];

        $bban = $bankCode . $prefix . $accountNumber;
        $checksum = $this->ibanChecksum('CZ', $bban);

        return 'CZ' . $checksum . $bban;
    }

    private function ibanChecksum(string $countryCode, string $bban): string
    {
        // Algoritmus IBAN MOD-97-10: countryCode + "00" se přesune na konec,
        // písmena na čísla (A=10, B=11, ...), pak mod 97, kontrolní = 98 - mod.
        $rearranged = $bban . $countryCode . '00';
        $numeric = '';
        foreach (str_split($rearranged) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string) (ord(strtoupper($ch)) - 55) : $ch;
        }
        $mod = (int) bcmod($numeric, '97');

        return str_pad((string) (98 - $mod), 2, '0', STR_PAD_LEFT);
    }

    private function sanitizeMessage(string $message): string
    {
        // mPDF qrcode lib má bug s vícebajtovým UTF-8 v byte módu — diakritika
        // se v QR poškodí. Transliterujeme do ASCII a zahodíme speciální znaky.
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $message) ?: '';
        $ascii = preg_replace('/[^A-Za-z0-9 \-.]/', '', $ascii) ?? '';

        return mb_substr(trim($ascii), 0, 60);
    }
}
