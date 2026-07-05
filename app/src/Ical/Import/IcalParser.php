<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Ical\Import;

/**
 * Parser iCal (RFC 5545) feedu obsazenosti z OTA (Airbnb, Booking, eChalupy,
 * CS chalupy). Zajímají nás jen VEVENT bloky jako „obsazeno" — z každého vezme
 * UID, DTSTART, DTEND, SUMMARY a STATUS. Průhledné události (TRANSP:TRANSPARENT)
 * kapacitu neblokují, takže je přeskočí. Inverzní logika k {@see \App\Ical\ICalendarWriter}.
 */
final class IcalParser
{
    /**
     * @return list<IcalEvent>
     */
    public function parse(string $ical): array
    {
        $events = [];
        $current = null;

        foreach ($this->unfold($ical) as $line) {
            $upper = strtoupper($line);
            if ($upper === 'BEGIN:VEVENT') {
                $current = [];
                continue;
            }
            if ($upper === 'END:VEVENT') {
                if ($current !== null) {
                    $event = $this->buildEvent($current);
                    if ($event !== null) {
                        $events[] = $event;
                    }
                }
                $current = null;
                continue;
            }
            if ($current === null) {
                continue;
            }

            [$name, $params, $value] = $this->splitProperty($line);
            if ($name === '') {
                continue;
            }
            $current[$name] = ['params' => $params, 'value' => $value];
        }

        return $events;
    }

    /**
     * Složí zalomené řádky zpět (pokračovací řádek začíná mezerou nebo tabem) a
     * vrátí neprázdné logické řádky. Přijímá CRLF i LF.
     *
     * @return list<string>
     */
    private function unfold(string $ical): array
    {
        $raw = preg_split('/\r\n|\r|\n/', $ical) ?: [];
        $lines = [];
        foreach ($raw as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $lines !== []) {
                $lines[count($lines) - 1] .= substr($line, 1);
            } else {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Rozdělí `NAME;PARAM=X:value` na název (velkými), parametry a hodnotu.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function splitProperty(string $line): array
    {
        $colon = strpos($line, ':');
        if ($colon === false) {
            return ['', '', ''];
        }
        $head = substr($line, 0, $colon);
        $value = substr($line, $colon + 1);

        $semicolon = strpos($head, ';');
        if ($semicolon === false) {
            return [strtoupper($head), '', $value];
        }

        return [
            strtoupper(substr($head, 0, $semicolon)),
            strtoupper(substr($head, $semicolon + 1)),
            $value,
        ];
    }

    /**
     * @param array<string, array{params: string, value: string}> $props
     */
    private function buildEvent(array $props): ?IcalEvent
    {
        if (($props['TRANSP']['value'] ?? '') !== '' && strtoupper($props['TRANSP']['value']) === 'TRANSPARENT') {
            return null;
        }

        $uid = trim($props['UID']['value'] ?? '');
        $start = $this->parseDate($props['DTSTART']['value'] ?? null);
        if ($uid === '' || $start === null) {
            return null;
        }

        return new IcalEvent(
            $uid,
            $start,
            $this->parseDate($props['DTEND']['value'] ?? null),
            $this->unescape($props['SUMMARY']['value'] ?? ''),
            strtoupper(trim($props['STATUS']['value'] ?? '')) === 'CANCELLED',
        );
    }

    /**
     * Datum z DTSTART/DTEND. Bere kalendářní den z prvních osmi číslic (VALUE=DATE
     * `20260413` i datetime `20260413T100000Z`) — pro obsazenost je den výlučný,
     * čas ani pásmo neřešíme.
     */
    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (!preg_match('/(\d{8})/', $value, $m)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Ymd', $m[1]);

        return $date instanceof \DateTimeImmutable ? $date : null;
    }

    /** Reverz escapování textové hodnoty dle RFC 5545 (jeden průchod přes escape sekvence). */
    private function unescape(string $value): string
    {
        return preg_replace_callback(
            '/\\\\([\\\\;,nN])/',
            static fn (array $m): string => match ($m[1]) {
                'n', 'N' => "\n",
                default => $m[1],
            },
            $value,
        ) ?? $value;
    }
}
