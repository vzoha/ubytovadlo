<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email;

use App\Email\Dto\AirbnbPayoutData;
use App\Formatting\CzechCalendar;

/**
 * Parsuje Airbnb e-mail "Poslali jsme ti výplatu ve výši …".
 * Na rozdíl od potvrzovacího e-mailu nese REÁLNÉ datum a částku výplaty
 * (kdy Airbnb peníze odeslal) — podklad pro datum úhrady faktury hostovi.
 */
class AirbnbPayoutParser
{
    private const FROM_ADDRESS = 'automated@airbnb.com';
    private const SUBJECT_PATTERN = '/Poslali jsme ti\s+v[ýy]platu/u';

    public function supports(EmailMessage $email): bool
    {
        if ($email->fromAddress !== null
            && stripos($email->fromAddress, self::FROM_ADDRESS) !== false) {
            return (bool) preg_match(self::SUBJECT_PATTERN, $email->subject);
        }

        return false;
    }

    public function parse(EmailMessage $email): AirbnbPayoutData
    {
        if (!$this->supports($email)) {
            throw new \InvalidArgumentException('E-mail does not look like an Airbnb payout notification.');
        }

        $text = EmailText::normalizeWhitespace($email->textBody);
        $reference = $email->date;

        return new AirbnbPayoutData(
            confirmationCode: $this->extractConfirmationCode($text),
            payoutAmount: $this->extractPayoutAmount($text),
            payoutSentAt: $this->extractSentDate($text, $reference),
            payoutExpectedAt: $this->extractExpectedDate($text),
            payoutReference: $this->extractPayoutReference($text),
            guestName: $this->extractGuestName($text),
        );
    }

    private function extractPayoutAmount(string $text): float
    {
        if (preg_match('/Dnes jsme ti odeslali částku\s+([\d\s\xc2\xa0]+,\d{2})/u', $text, $m)) {
            return EmailText::parseCzechNumber($m[1]);
        }
        // Fallback na předmět/tělo: "výplatu ve výši 2 500,00 Kč"
        if (preg_match('/ve výši\s+([\d\s\xc2\xa0]+,\d{2})/u', $text, $m)) {
            return EmailText::parseCzechNumber($m[1]);
        }
        throw new \RuntimeException('Cannot extract Airbnb payout amount.');
    }

    private function extractSentDate(string $text, \DateTimeImmutable $reference): \DateTimeImmutable
    {
        // "Tvé peníze byly odeslány dne 29. května" — rok chybí, doplníme z data e-mailu.
        if (!preg_match('/odeslán[yi]\s+dne\s+(\d{1,2})\.\s*(\p{L}+)/u', $text, $m)) {
            throw new \RuntimeException('Cannot extract Airbnb payout sent date.');
        }
        $day = (int) $m[1];
        $month = $this->monthFromName($m[2]);

        return $this->withInferredYear($day, $month, $reference);
    }

    private function extractExpectedDate(string $text): ?\DateTimeImmutable
    {
        // "měly by dorazit do 5. června 2026" — rok je uveden přímo.
        if (preg_match('/dorazit do\s+(\d{1,2})\.\s*(\p{L}+)\s+(\d{4})/u', $text, $m)) {
            $month = $this->monthFromName($m[2]);

            return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $m[3], $month, (int) $m[1]));
        }

        return null;
    }

    private function extractPayoutReference(string $text): ?string
    {
        if (preg_match('/Identifikační číslo výplaty\s+([A-Z0-9-]{6,})/u', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractConfirmationCode(string $text): string
    {
        // Potvrzující kód stojí v sekci Podrobnosti hned za ID inzerátu v závorce:
        // "<název inzerátu> (1234567890123456)  HMMNOP56QR".
        if (preg_match('/\(\d{6,}\)\s+([A-Z0-9]{8,12})\b/u', $text, $m)) {
            return $m[1];
        }
        throw new \RuntimeException('Cannot extract Airbnb confirmation code from payout e-mail.');
    }

    private function extractGuestName(string $text): ?string
    {
        // "Podrobnosti  Eva Marková   2 500,00 Kč CZK"
        if (preg_match('/Podrobnosti\s+(\p{Lu}[\p{L}\s\']+?)\s+[\d\s\xc2\xa0]+,\d{2}\s*Kč/u', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function monthFromName(string $name): int
    {
        $key = mb_strtolower(trim($name));
        $months = CzechCalendar::genitiveMonths();
        if (!isset($months[$key])) {
            throw new \RuntimeException("Unknown Czech month name: $name");
        }

        return $months[$key];
    }

    /**
     * Datum bez roku — vybere rok (z okolí data e-mailu), pro který je výsledek
     * nejblíže referenčnímu dni. Řeší přelom roku (prosinec vs. leden).
     */
    private function withInferredYear(int $day, int $month, \DateTimeImmutable $reference): \DateTimeImmutable
    {
        $referenceYear = (int) $reference->format('Y');
        $referenceTs = $reference->setTime(0, 0)->getTimestamp();

        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ([$referenceYear - 1, $referenceYear, $referenceYear + 1] as $year) {
            $candidate = \DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day));
            if ($candidate === false) {
                continue;
            }
            $distance = abs($candidate->getTimestamp() - $referenceTs);
            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best ?? new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $referenceYear, $month, $day));
    }
}
