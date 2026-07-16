<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email;

use App\Email\Dto\AirbnbParsedReservation;
use App\Reservation\GuestRequestKeywords;

class AirbnbReservationParser
{
    private const FROM_ADDRESS = 'automated@airbnb.com';
    private const SUBJECT_PATTERN = '/^Rezervace potvrzena\s*-\s*(?<name>.+?)\s+přijede\s+\d/u';

    public function supports(EmailMessage $email): bool
    {
        if ($email->fromAddress !== null
            && stripos($email->fromAddress, self::FROM_ADDRESS) !== false) {
            return (bool) preg_match(self::SUBJECT_PATTERN, $email->subject);
        }

        return false;
    }

    public function parse(EmailMessage $email): AirbnbParsedReservation
    {
        if (!$this->supports($email)) {
            throw new \InvalidArgumentException('E-mail does not look like an Airbnb reservation confirmation.');
        }

        $text = EmailText::normalizeWhitespace($email->textBody);
        $reference = $email->date;

        $name = $this->extractName($email->subject, $text);
        $confirmationCode = $this->extractConfirmationCode($text);
        [$checkIn, $checkInTime] = $this->extractMomentLine($text, 'Příjezd', $reference);
        [$checkOut, $checkOutTime] = $this->extractMomentLine($text, 'Odjezd', $reference, after: $checkIn);
        [$adult, $child, $infant] = $this->extractGuestCounts($text);
        [$pricePerNight, $nights, $subtotal] = $this->extractStayPricing($text);
        $hostCommission = $this->extractAmount($text, '/Servisní poplatek hostitele[^-]*-\s*([\d\s\xc2\xa0]+,\d{2})/u');
        $netPayout = $this->extractAmount($text, '/Vyděláš si\s+([\d\s\xc2\xa0]+,\d{2})/u');
        // price_total = hrubá tržba hostitele (čistý výdělek + jeho servisní poplatek),
        // NE guest total „Celkem (CZK)" — ten obsahuje i servisní poplatek hosta a daně,
        // které si Airbnb bere od hosta a hostiteli nikdy nedojdou. Fallback: ubytování.
        $priceTotal = $netPayout !== null && $hostCommission !== null
            ? $netPayout + $hostCommission
            : $subtotal;
        [$hasPet, $petsNote] = $this->detectPet($text);

        return new AirbnbParsedReservation(
            confirmationCode: $confirmationCode,
            guestName: $name,
            guestRegion: $this->extractRegion($text, $name),
            checkIn: $checkIn,
            checkOut: $checkOut,
            checkInTime: $checkInTime,
            checkOutTime: $checkOutTime,
            guestsAdult: $adult,
            guestsChild: $child,
            guestsInfant: $infant,
            pricePerNight: $pricePerNight,
            nights: $nights,
            priceTotal: $priceTotal,
            hostCommission: $hostCommission,
            netPayout: $netPayout,
            hasPet: $hasPet,
            petsNote: $petsNote,
        );
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function detectPet(string $text): array
    {
        // Airbnb e-mail typicky obsahuje "Asistenční zvíře" / "Service animal" nebo
        // pocet mazlicku v sekci Hoste ("1 domácí mazlíček" / "1 pet"). Formulace
        // se lisi podle lokalizace e-mailu, scanujeme vse najednou.
        if (preg_match('/(\d+)\s+(?:domác[ií](?:ch)?\s+)?mazlíč[\p{L}]*/iu', $text, $m) === 1 && (int) $m[1] > 0) {
            return [true, trim($m[0])];
        }
        if (preg_match('/(\d+)\s+pets?\b/iu', $text, $m) === 1 && (int) $m[1] > 0) {
            return [true, trim($m[0])];
        }
        if (preg_match('/asistenční\s+zvíře|service\s+animal/iu', $text, $m) === 1) {
            return [true, trim($m[0])];
        }
        $petMatch = GuestRequestKeywords::matchPet($text);
        if ($petMatch !== null) {
            return [true, $petMatch];
        }

        return [false, null];
    }

    private function extractName(string $subject, string $text): string
    {
        if (preg_match(self::SUBJECT_PATTERN, $subject, $m)) {
            return trim($m['name']);
        }
        if (preg_match('/Rezervace potvrzena!\s+(?<name>[^.]+?)\s+dorazí/u', $text, $m)) {
            return trim($m['name']);
        }
        throw new \RuntimeException('Cannot extract guest name from Airbnb e-mail.');
    }

    private function extractConfirmationCode(string $text): string
    {
        if (preg_match('/Potvrzující kód\s+([A-Z0-9]{6,})/u', $text, $m)) {
            return $m[1];
        }
        throw new \RuntimeException('Cannot extract Airbnb confirmation code.');
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function extractMomentLine(string $text, string $label, \DateTimeImmutable $reference, ?\DateTimeImmutable $after = null): array
    {
        $pattern = sprintf(
            '/%s\s+(?:po|út|st|čt|pá|so|ne)\s+(?<day>\d{1,2})\.\s*(?<month>\d{1,2})\.(?:\s+(?<hour>\d{1,2}):(?<minute>\d{2}))?/u',
            preg_quote($label, '/')
        );
        if (!preg_match($pattern, $text, $m)) {
            throw new \RuntimeException("Cannot extract '$label' line from Airbnb e-mail.");
        }
        $day = (int) $m['day'];
        $month = (int) $m['month'];
        $year = $this->resolveYear($month, $day, $reference, $after);
        $date = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));

        $time = null;
        if (isset($m['hour'])) {
            $time = new \DateTimeImmutable(sprintf('1970-01-01 %02d:%02d:00', (int) $m['hour'], (int) $m['minute']));
        }

        return [$date, $time];
    }

    private function resolveYear(int $month, int $day, \DateTimeImmutable $reference, ?\DateTimeImmutable $after): int
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
            if ($after !== null && $candidate < $after) {
                continue;
            }
            $distance = abs($candidate->getTimestamp() - $referenceTs);
            if ($distance < $bestDistance) {
                $best = $year;
                $bestDistance = $distance;
            }
        }

        return $best ?? $referenceYear;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function extractGuestCounts(string $text): array
    {
        $adult = $child = $infant = 0;
        if (preg_match('/Hosté\s+(\d+)\s+dospěl/u', $text, $m)) {
            $adult = (int) $m[1];
        }
        if (preg_match('/(\d+)\s+(?:dít|dět)/u', $text, $m)) {
            $child = (int) $m[1];
        }
        if (preg_match('/(\d+)\s+kojen/u', $text, $m)) {
            $infant = (int) $m[1];
        }

        return [$adult, $child, $infant];
    }

    /**
     * @return array{0: ?float, 1: ?int, 2: ?float}
     */
    private function extractStayPricing(string $text): array
    {
        $pricePerNight = $nights = $subtotal = null;
        if (preg_match('/Host zaplatil\s+([\d\s\xc2\xa0]+,\d{2})\s*Kč\s*x\s*(\d+)\s*(?:noc|noci|nocí)\s+([\d\s\xc2\xa0]+,\d{2})/u', $text, $m)) {
            $pricePerNight = $this->parseCzNumber($m[1]);
            $nights = (int) $m[2];
            $subtotal = $this->parseCzNumber($m[3]);
        }

        return [$pricePerNight, $nights, $subtotal];
    }

    private function extractAmount(string $text, string $pattern): ?float
    {
        if (preg_match($pattern, $text, $m)) {
            return $this->parseCzNumber($m[1]);
        }

        return null;
    }

    private function extractRegion(string $text, string $guestName): ?string
    {
        $namePos = mb_strpos($text, $guestName);
        if ($namePos === false) {
            return null;
        }
        $window = mb_substr($text, $namePos, 400);

        // Region hosta ("Město/kraj, Země") stojí hned za značkou "Totožnost ověřena
        // [· N hodnocení]". Kotvíme strukturou: region končí jednoslovnou zemí, za níž
        // následuje název inzerátu — díky tomu nepotřebujeme znát jméno inzerátu předem.
        if (preg_match('/Totožnost ověřena(?:[^A-ZÁ-Ž\n]*?\d+\s+hodnocení)?\s+([A-ZÁ-Ž][\p{L} ]{0,40}?,\s*[A-ZÁ-Ž]\p{Ll}+)(?=\s)/u', $window, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function parseCzNumber(string $s): float
    {
        $s = preg_replace('/[\s\xc2\xa0]+/', '', $s) ?? $s;
        $s = str_replace(',', '.', $s);

        return (float) $s;
    }
}
