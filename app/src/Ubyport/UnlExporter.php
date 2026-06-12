<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Ubyport;

use App\Entity\AccommodationProfile;
use App\Entity\GuestDocument;

/**
 * Generuje UNL soubor pro hlášení ubytovaných cizinců do Ubyportu
 * (ubyport.policie.cz → "Import dat (UNL soubor)").
 *
 * Formát (ověřeno proti sources/ubyport/123456789012_1512060426_vzor.unl):
 *   - encoding Windows-1250
 *   - line ending CRLF (Windows IIS server)
 *   - oddělovač polí pipe '|'
 *   - každý řádek má pevný počet polí + trailing pipe (poslední pole prázdné)
 *   - hlavička "A" 1 řádek na začátku, datové řádky "U" pro každého hosta
 *
 * Pole na "U" řádku, jejichž význam zatím neznáme jistě (jsou ve vzorku prázdná):
 *   - index 5 (mezi jmenem a datem narozeni) — pravděpodobně prostřední/jiné jméno
 *   - indexy 7, 8 (po datu narození) — patrně nevyužité rezervy formátu
 */
final class UnlExporter
{
    private const HEADER_RECORD = 'A';
    private const GUEST_RECORD = 'U';
    private const FORMAT_VERSION = '2';
    private const DELIMITER = '|';
    private const LINE_ENDING = "\r\n";

    /**
     * @param GuestDocument[] $documents Cizinci k nahlášení, předfiltrovaní volajícím
     *                                   (is_czech_citizen=false, confirmed_at IS NOT NULL).
     *                                   Každý GuestDocument musí mít navázanou rezervaci
     *                                   s vyplněným check-out datem.
     */
    public function build(
        AccommodationProfile $profile,
        array $documents,
        \DateTimeImmutable $generatedAt,
    ): UnlExportResult {
        $lines = [$this->headerLine($profile, $generatedAt)];
        foreach ($documents as $doc) {
            $lines[] = $this->guestLine($doc);
        }

        $utf8 = implode(self::LINE_ENDING, $lines) . self::LINE_ENDING;
        $win1250 = iconv('UTF-8', 'WINDOWS-1250', $utf8);
        if ($win1250 === false) {
            throw new \RuntimeException('Nepodarilo se prevest UNL obsah do Windows-1250 (znak mimo CP1250?).');
        }

        $filename = sprintf('%s_%s.unl', $profile->getIdub(), $generatedAt->format('ymdHi'));

        return new UnlExportResult($win1250, $filename, count($documents));
    }

    private function headerLine(AccommodationProfile $p, \DateTimeImmutable $at): string
    {
        return $this->joinRow([
            self::HEADER_RECORD,
            self::FORMAT_VERSION,
            $p->getIdub(),
            $p->getKod(),
            $p->getNazev(),
            $p->getSpojeni(),
            $p->getOkres(),
            $p->getObec(),
            $p->getCastObce() ?? '',
            $p->getUlice() ?? '',
            $p->getCp() ?? '',
            $p->getCo() ?? '',
            $p->getPsc(),
            $at->format('Y.m.d H:i:s'),
            '',
        ]);
    }

    private function guestLine(GuestDocument $g): string
    {
        $r = $g->getReservation();
        $checkOut = $this->requireNotNull($r->getCheckOut(), $g, 'check-out na rezervaci');
        $nationality = $this->requireNotNull($g->getNationalityCode(), $g, 'občanství');
        $documentNumber = $this->requireNotNull($g->getDocumentNumber(), $g, 'číslo dokladu');

        return $this->joinRow([
            self::GUEST_RECORD,
            $r->getCheckIn()->format('d.m.Y'),
            $checkOut->format('d.m.Y'),
            mb_strtoupper($g->getLastName(), 'UTF-8'),
            mb_strtoupper($g->getFirstName(), 'UTF-8'),
            '',
            $g->getBirthDate()->format('d.m.Y'),
            '',
            '',
            $nationality,
            $this->singleLine($g->getPermanentResidenceAbroad()),
            $documentNumber,
            $g->getVisaNumber() ?? '',
            $r->getUbyportPurposeOfStay()->value,
            '',
        ]);
    }

    /**
     * @template T
     *
     * @param T|null $value
     *
     * @return T
     */
    private function requireNotNull(mixed $value, GuestDocument $g, string $field): mixed
    {
        if ($value === null) {
            throw new \LogicException(sprintf('GuestDocument #%d: chybi povinne pole "%s" pro Ubyport export.', $g->getId() ?? -1, $field));
        }

        return $value;
    }

    /**
     * @param list<string> $fields
     */
    private function joinRow(array $fields): string
    {
        return implode(self::DELIMITER, $fields) . self::DELIMITER;
    }

    private function singleLine(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
