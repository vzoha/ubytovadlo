<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mrz;

use App\Enum\DocumentType;

final class MrzParser
{
    private const ICAO_TO_ISO = [
        'D' => 'DEU',
        'GBD' => 'GBR',
        'GBN' => 'GBR',
        'GBO' => 'GBR',
        'GBP' => 'GBR',
        'GBS' => 'GBR',
        'RKS' => 'XKX',
        'EUE' => 'EUE',
        'UNO' => 'UNO',
        'UNA' => 'UNA',
        'UNK' => 'UNK',
        'XXA' => 'XXA',
        'XXB' => 'XXB',
        'XXC' => 'XXC',
        'XXX' => 'XXX',
    ];

    public function parse(string $raw): ?MrzResult
    {
        // French CNIs need anchoring before the generic length-based extractor:
        // their fixed 36-char lines pick up leading/trailing OCR noise that
        // shifts every field. Detect them by the IDFRA marker and re-anchor on
        // a checksum-valid document number.
        if (str_contains(strtoupper($raw), 'IDFRA')) {
            $fr = $this->parseFrenchFromRaw($raw);
            if ($fr !== null && $this->resultLooksReal($fr)) {
                return $fr;
            }
        }

        $lines = $this->extractMrzLines($raw);
        if ($lines !== null) {
            $result = match (true) {
                \count($lines) === 2 && $this->isFrenchId($lines[0]) => $this->parseFrenchId($lines),
                \count($lines) === 2 => $this->parseTwoLine($lines),
                \count($lines) === 3 => $this->parseTd1($lines),
                default => null,
            };
            if ($result !== null && $this->resultLooksReal($result)) {
                return $result;
            }
        }

        return $this->parseWithSlidingWindow($raw);
    }

    /**
     * Parse several OCR reads of the SAME document (one per preprocessing
     * variant) and merge them by per-field majority vote. The check digits
     * cover the numeric fields but never the name zone, so two variants can be
     * equally "confident" yet disagree on a surname or given name; a single
     * winner-takes-all pick then depends on arbitrary ordering. Voting lets the
     * reading that most variants agree on win each field independently — e.g.
     * three variants reading GERHARD<LUDWIG outvote one misreading LUDWILG.
     *
     * Only the most confident tier of reads votes, so a garbage variant cannot
     * outweigh the genuine ones.
     *
     * @param list<string> $rawTexts
     */
    public function parseMany(array $rawTexts): ?MrzResult
    {
        $results = [];
        foreach ($rawTexts as $text) {
            $r = $this->parse($text);
            if ($r !== null) {
                $results[] = $r;
            }
        }
        if ($results === []) {
            return null;
        }

        usort($results, static fn (MrzResult $a, MrzResult $b) => $b->confidence <=> $a->confidence);

        // Vote among every checksum-solid read (>= 20 ≈ two valid ICAO check
        // digits, i.e. a genuine MRZ rather than noise). We deliberately do NOT
        // narrow to a band just below the top score: a variant can read the
        // numeric lines slightly worse — lowering its confidence — yet read the
        // name zone correctly, and those are exactly the votes that fix a
        // garbled surname/given name on the otherwise highest-scoring variant.
        $strong = array_values(array_filter(
            $results,
            static fn (MrzResult $r) => $r->confidence >= 20,
        ));

        return \count($strong) >= 2 ? $this->voteFields($strong) : $results[0];
    }

    /**
     * Merge a set of confident results into one by per-field weighted vote. Each
     * read contributes its own confidence as weight, so a clean high-confidence
     * read outweighs several noisy ones rather than being out-counted by them.
     * This is what lets the browser pipeline OCR a photo several ways (some crops
     * clip a glyph off the name zone) without a low-quality read corrupting the
     * winning field: pure majority counting let "BVLATUSEK" beat "LATUSEK" when
     * more crops happened to be noisy; weighting by confidence does not.
     *
     * Name fields additionally get a small clean-shape bonus so that, among reads
     * of comparable confidence, a well-formed surname/given name is preferred.
     *
     * @param list<MrzResult> $results
     */
    private function voteFields(array $results): MrzResult
    {
        $nameBonus = fn (string $v): int => preg_match('/^[A-Z]{2,}( [A-Z]+)*$/', $v) === 1 ? 4 : 0;

        $birth = $this->weightedModal($results, static fn (MrzResult $r) => $r->birthDate->format('Y-m-d'));
        $expiry = $this->weightedModal($results, static fn (MrzResult $r) => $r->expiryDate?->format('Y-m-d') ?? '');

        return new MrzResult(
            lastName: $this->weightedModal($results, static fn (MrzResult $r) => $r->lastName, $nameBonus),
            firstName: $this->weightedModal($results, static fn (MrzResult $r) => $r->firstName, $nameBonus),
            birthDate: new \DateTimeImmutable($birth),
            sex: $this->weightedModal($results, static fn (MrzResult $r) => $r->sex),
            nationalityCode: $this->weightedModal($results, static fn (MrzResult $r) => $r->nationalityCode),
            documentType: DocumentType::from($this->weightedModal($results, static fn (MrzResult $r) => $r->documentType->value)),
            documentNumber: $this->weightedModal($results, static fn (MrzResult $r) => $r->documentNumber),
            expiryDate: $expiry === '' ? null : new \DateTimeImmutable($expiry),
            confidence: $results[0]->confidence,
        );
    }

    /**
     * Per-field winner by confidence-weighted vote. Each result adds its
     * confidence (floored at 1 so a zero-confidence read still counts a little)
     * plus an optional value-shape bonus to its field value's tally; the highest
     * total wins. Ties resolve to whichever appeared first, and $results is
     * pre-sorted confidence-descending, so the most confident read breaks them.
     *
     * @param list<MrzResult>             $results
     * @param callable(MrzResult): string $extract
     * @param callable(string): int|null  $bonus
     */
    private function weightedModal(array $results, callable $extract, ?callable $bonus = null): string
    {
        $weights = [];
        foreach ($results as $r) {
            $value = $extract($r);
            $weight = max(1, $r->confidence) + ($bonus !== null ? $bonus($value) : 0);
            $weights[$value] = ($weights[$value] ?? 0) + $weight;
        }
        arsort($weights);

        return (string) array_key_first($weights);
    }

    /**
     * Sliding-window fallback for noisy multi-line OCR (real photos with
     * non-MRZ text leaking into the OCR output, garbage prefixes, etc.).
     * Tries every 44/36/30-char substring of every cleaned line, scores
     * combinations by MRZ check-digit validity, returns highest-scoring.
     */
    private function parseWithSlidingWindow(string $raw): ?MrzResult
    {
        $raw = strtoupper(str_replace(["\r\n", "\r"], "\n", trim($raw)));
        $cleaned = [];
        foreach (explode("\n", $raw) as $line) {
            $clean = $this->cleanMrzLine($line);
            if (\strlen($clean) >= 28) {
                $cleaned[] = $clean;
            }
        }
        if (\count($cleaned) === 0) {
            return null;
        }

        $formats = [
            ['len' => 44, 'count' => 2, 'parse' => 'parseTwoLine'],
            ['len' => 36, 'count' => 2, 'parse' => 'parseTwoLine'],
            ['len' => 30, 'count' => 3, 'parse' => 'parseTd1'],
        ];

        $bestScore = 0;
        $bestResult = null;

        foreach ($formats as $fmt) {
            $perLine = [];
            foreach ($cleaned as $line) {
                $windows = $this->generateWindows($line, $fmt['len']);
                if ($windows !== []) {
                    $perLine[] = $windows;
                }
            }
            if (\count($perLine) < $fmt['count']) {
                continue;
            }

            for ($start = 0; $start + $fmt['count'] <= \count($perLine); $start++) {
                $groups = \array_slice($perLine, $start, $fmt['count']);
                foreach ($this->cartesian($groups) as $combo) {
                    $score = $this->scoreCombo($combo, $fmt['len'], $fmt['count']);
                    if ($score <= $bestScore) {
                        continue;
                    }
                    $candidate = ($fmt['parse'] === 'parseTwoLine')
                        ? $this->parseTwoLine($combo)
                        : $this->parseTd1($combo);
                    if ($candidate !== null && $this->resultLooksReal($candidate)) {
                        $bestScore = $score;
                        $bestResult = $candidate;
                    }
                }
            }
        }

        return ($bestScore >= 15) ? $bestResult : null;
    }

    /**
     * Reject sliding-window results that pass parsing but are obviously noise:
     * nationality must be 3 A-Z chars, doc number 5+ chars without fillers,
     * last name 2+ letters.
     */
    private function resultLooksReal(MrzResult $r): bool
    {
        if (\strlen($r->nationalityCode) !== 3 || preg_match('/^[A-Z]{3}$/', $r->nationalityCode) !== 1) {
            return false;
        }
        if (\strlen($r->documentNumber) < 5 || str_contains($r->documentNumber, '<')) {
            return false;
        }
        if (preg_match('/^[A-Z]{3,}/', $r->lastName) !== 1) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function generateWindows(string $line, int $targetLen): array
    {
        $len = \strlen($line);
        if ($len === $targetLen) {
            return [$line];
        }
        if ($len < $targetLen) {
            return ($targetLen - $len <= 4) ? [str_pad($line, $targetLen, '<')] : [];
        }

        $windows = [];
        for ($i = 0; $i <= $len - $targetLen; $i++) {
            $windows[] = substr($line, $i, $targetLen);
        }

        return $windows;
    }

    /**
     * @param list<list<string>> $groups
     *
     * @return \Generator<list<string>>
     */
    private function cartesian(array $groups): \Generator
    {
        if (\count($groups) === 1) {
            foreach ($groups[0] as $item) {
                yield [$item];
            }

            return;
        }

        $first = $groups[0];
        $rest = \array_slice($groups, 1);
        foreach ($first as $item) {
            foreach ($this->cartesian($rest) as $combo) {
                yield array_merge([$item], $combo);
            }
        }
    }

    /**
     * Score a candidate MRZ combination by check-digit validity and structural
     * markers. Higher = more likely a valid MRZ. 10+ means at least one
     * check digit matched (= very likely real MRZ, not random noise).
     *
     * @param list<string> $combo
     */
    private function scoreCombo(array $combo, int $targetLen, int $count): int
    {
        $score = 0;

        if ($count === 2 && $targetLen === 44) {
            $line1 = $combo[0];
            $line2 = $combo[1];

            if (\in_array($line1[0], ['P', 'I', 'A', 'C'], true)) {
                $score += 2;
            }
            if (str_contains($line1, '<<')) {
                $score += 2;
            }

            $issuingCountry = substr($line1, 2, 3);
            $nationality = substr($line2, 10, 3);
            $issuingIsLetters = preg_match('/^[A-Z]{3}$/', $issuingCountry) === 1;
            if ($issuingIsLetters && $issuingCountry === $nationality) {
                $score += 6;
            } elseif ($issuingIsLetters) {
                $score += 3;
            }

            $docNum = substr($line2, 0, 9);
            if ($this->checkDigit($docNum) === ($line2[9] ?? '')) {
                $score += 10;
            }

            $dob = substr($line2, 13, 6);
            if (ctype_digit($dob) && $this->checkDigit($dob) === ($line2[19] ?? '')) {
                $score += 5;
            }

            $exp = substr($line2, 21, 6);
            if (ctype_digit($exp) && $this->checkDigit($exp) === ($line2[27] ?? '')) {
                $score += 5;
            }

            if (\in_array($line2[20] ?? '', ['M', 'F', '<'], true)) {
                $score++;
            }

            return $score;
        }

        if ($count === 2 && $targetLen === 36) {
            $line1 = $combo[0];
            $line2 = $combo[1];

            if (\in_array($line1[0], ['I', 'A', 'C'], true)) {
                $score += 2;
            }
            if (str_contains($line1, '<<')) {
                $score += 2;
            }

            $issuingCountry = substr($line1, 2, 3);
            $nationality = substr($line2, 10, 3);
            $issuingIsLetters = preg_match('/^[A-Z]{3}$/', $issuingCountry) === 1;
            if ($issuingIsLetters && $issuingCountry === $nationality) {
                $score += 6;
            } elseif ($issuingIsLetters) {
                $score += 3;
            }

            $docNum = substr($line2, 0, 9);
            if ($this->checkDigit($docNum) === ($line2[9] ?? '')) {
                $score += 10;
            }

            $dob = substr($line2, 13, 6);
            if (ctype_digit($dob) && $this->checkDigit($dob) === ($line2[19] ?? '')) {
                $score += 5;
            }

            return $score;
        }

        if ($count === 3 && $targetLen === 30) {
            $line1 = $combo[0];
            $line2 = $combo[1];
            $line3 = $combo[2];

            if (\in_array($line1[0], ['I', 'A', 'C'], true)) {
                $score += 2;
            }
            if (str_contains($line3, '<<')) {
                $score += 2;
            }

            // Suspicious prefix: if line 3 starts with the same letter as
            // line 1's doc-type code (I/A/C), it's usually a stray edge
            // character bleeding into the OCR, not the true surname.
            if (\in_array($line1[0], ['I', 'A', 'C'], true) && $line3[0] === $line1[0]) {
                $score -= 3;
            }

            $issuingCountry = substr($line1, 2, 3);
            $nationality = substr($line2, 15, 3);
            $issuingIsLetters = preg_match('/^[A-Z]{3}$/', $issuingCountry) === 1;
            if ($issuingIsLetters && $issuingCountry === $nationality) {
                $score += 6;
            } elseif ($issuingIsLetters) {
                $score += 3;
            }

            $docNum = substr($line1, 5, 9);
            if ($this->checkDigit($docNum) === ($line1[14] ?? '')) {
                $score += 10;
            }

            $dob = substr($line2, 0, 6);
            if (ctype_digit($dob) && $this->checkDigit($dob) === ($line2[6] ?? '')) {
                $score += 5;
            }

            $exp = substr($line2, 8, 6);
            if (ctype_digit($exp) && $this->checkDigit($exp) === ($line2[14] ?? '')) {
                $score += 5;
            }

            return $score;
        }

        return 0;
    }

    /**
     * Reward clean, plausible name fields so that — among variants whose check
     * digits all validate equally — the one with a readable surname wins. The
     * check digits never cover the name zone, so without this an OCR variant
     * that nails the dates but garbles the surname (digits, stray letters)
     * could beat a variant that read the name cleanly.
     */
    private function nameQuality(string $lastName, string $firstName): int
    {
        $q = 0;
        if (preg_match('/^[A-Z]{2,}( [A-Z]+)*$/', $lastName) === 1) {
            $q += 4;
        }
        if ($firstName !== '' && preg_match('/^[A-Z]{2,}( [A-Z]+)*$/', $firstName) === 1) {
            $q += 2;
        }

        return $q;
    }

    /**
     * Reduce one OCR text line to its MRZ payload. A real MRZ row never
     * contains spaces, so any whitespace separates genuine MRZ characters from
     * OCR noise picked up around the band (a card edge, the transition strip
     * above the MRZ, background). We therefore keep the longest whitespace-
     * delimited run of MRZ characters when it is long enough to be a real row;
     * otherwise we fall back to gluing everything (so a space wrongly inserted
     * inside a row doesn't truncate it). This stops a stray leading token (e.g.
     * "T TROMMLER<<…") from being concatenated onto the surname ("TTROMMLER").
     */
    private function cleanMrzLine(string $line): string
    {
        $glued = preg_replace('/[^A-Z0-9<]/', '', $line) ?? '';

        $longest = '';
        foreach (preg_split('/\s+/', trim($line)) ?: [] as $token) {
            $clean = preg_replace('/[^A-Z0-9<]/', '', $token) ?? '';
            if (\strlen($clean) > \strlen($longest)) {
                $longest = $clean;
            }
        }

        return \strlen($longest) >= 28 ? $longest : $glued;
    }

    /**
     * ICAO 9303 mod-10 check digit. Letters A-Z map to 10-35, digits to
     * themselves, '<' to 0. Weights cycle 7,3,1.
     */
    private function checkDigit(string $data): string
    {
        $weights = [7, 3, 1];
        $sum = 0;
        $len = \strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $ch = $data[$i];
            if ($ch === '<') {
                $val = 0;
            } elseif (ctype_digit($ch)) {
                $val = (int) $ch;
            } elseif (ctype_upper($ch)) {
                $val = \ord($ch) - \ord('A') + 10;
            } else {
                return '';
            }
            $sum += $val * $weights[$i % 3];
        }

        return (string) ($sum % 10);
    }

    /**
     * @return list<string>|null
     */
    private function extractMrzLines(string $raw): ?array
    {
        $raw = strtoupper(str_replace(["\r\n", "\r"], "\n", trim($raw)));
        $candidates = [];

        foreach (explode("\n", $raw) as $line) {
            $clean = $this->cleanMrzLine($line);
            $len = \strlen($clean);
            if ($len >= 28 && $len <= 46) {
                $candidates[] = $clean;
            }
        }

        if (\count($candidates) === 3) {
            $normalized = array_map(fn (string $l) => $this->padOrTrim($l, 30), $candidates);

            return $normalized;
        }

        if (\count($candidates) === 2) {
            $len0 = \strlen($candidates[0]);
            $len1 = \strlen($candidates[1]);
            $avg = (int) round(($len0 + $len1) / 2);

            if ($avg >= 42 && $avg <= 46) {
                return array_map(fn (string $l) => $this->padOrTrim($l, 44), $candidates);
            }
            if ($avg >= 34 && $avg <= 38) {
                return array_map(fn (string $l) => $this->padOrTrim($l, 36), $candidates);
            }

            if ($len0 >= 40 || $len1 >= 40) {
                return array_map(fn (string $l) => $this->padOrTrim($l, 44), $candidates);
            }

            return array_map(fn (string $l) => $this->padOrTrim($l, 36), $candidates);
        }

        if (\count($candidates) > 3) {
            $bestTriple = $this->findBestGroup($candidates, 30, 3);
            if ($bestTriple !== null) {
                return $bestTriple;
            }
            $bestDouble = $this->findBestGroup($candidates, 44, 2)
                ?? $this->findBestGroup($candidates, 36, 2);
            if ($bestDouble !== null) {
                return $bestDouble;
            }
        }

        return null;
    }

    /**
     * @param list<string> $candidates
     *
     * @return list<string>|null
     */
    private function findBestGroup(array $candidates, int $targetLen, int $count): ?array
    {
        $scored = [];
        foreach ($candidates as $i => $c) {
            $scored[] = ['idx' => $i, 'line' => $c, 'diff' => abs(\strlen($c) - $targetLen)];
        }
        usort($scored, fn ($a, $b) => $a['diff'] <=> $b['diff']);

        if (\count($scored) < $count) {
            return null;
        }

        $picked = array_slice($scored, 0, $count);
        usort($picked, fn ($a, $b) => $a['idx'] <=> $b['idx']);

        foreach ($picked as $p) {
            if ($p['diff'] > 4) {
                return null;
            }
        }

        return array_map(
            fn ($p) => $this->padOrTrim($p['line'], $targetLen),
            $picked,
        );
    }

    /**
     * Visually-confusable character substitutions seen in OCR-B MRZ reads,
     * used to constrain check-digit repair to *plausible* misreads instead of
     * any arbitrary substitution. Keyed by the character the OCR produced.
     *
     * @var array<array-key, list<string>>
     */
    private const OCR_CONFUSIONS = [
        '0' => ['O', 'D', 'Q', '8', '6', '9', '5'],
        '1' => ['I', 'L', 'T', '7'],
        '2' => ['Z'],
        '3' => ['8'],
        '4' => ['A'],
        '5' => ['S', '6', '0', '8'],
        '6' => ['G', '8', '5', '0'],
        '7' => ['1', 'T'],
        '8' => ['B', '0', '6', '9', '3'],
        '9' => ['0', '8'],
        'A' => ['4'],
        'B' => ['8'],
        'D' => ['0', 'O'],
        'G' => ['6', 'C'],
        'I' => ['1', 'L'],
        'L' => ['1', 'I'],
        'O' => ['0', 'D', 'Q'],
        'Q' => ['0', 'O'],
        'S' => ['5'],
        'T' => ['1', '7'],
        'Z' => ['2'],
    ];

    /**
     * Repair a field against its ICAO check digit by trying single-character
     * substitutions. To avoid over-correcting we only try *visually plausible*
     * OCR confusions (e.g. 5↔0, 8↔B, 1↔7), and — for date fields — keep only
     * candidates that form a calendar-valid YYMMDD. The repair is applied only
     * when it resolves to exactly one candidate, so an ambiguous field is left
     * untouched. This fixes the common "one wrong digit" read (e.g. a DOB
     * 751209 misread as 701209) without inventing values.
     */
    private function repairCheckDigit(string $field, string $expectedCheck, bool $isDate = false): string
    {
        // Only date fields are repaired: the calendar-validity backstop keeps
        // the guess tight, and a wrong-but-plausible date is far rarer than a
        // wrong-but-plausible document number (which has no such backstop, so
        // "fixing" it risks corrupting a correctly read number when the check
        // digit itself was the misread character).
        if (!$isDate) {
            return $field;
        }
        if (!ctype_digit($expectedCheck)) {
            return $field;
        }
        if ($this->checkDigit($field) === $expectedCheck) {
            return $field;
        }

        $candidates = [];
        $len = \strlen($field);
        for ($pos = 0; $pos < $len; $pos++) {
            $orig = $field[$pos];
            foreach (self::OCR_CONFUSIONS[$orig] ?? [] as $alt) {
                $cand = substr_replace($field, $alt, $pos, 1);
                if ($this->checkDigit($cand) !== $expectedCheck) {
                    continue;
                }
                if (!$this->isCalendarDate($cand)) {
                    continue;
                }
                $candidates[] = $cand;
            }
        }

        $candidates = array_values(array_unique($candidates));

        return \count($candidates) === 1 ? $candidates[0] : $field;
    }

    /**
     * True if a 6-character string is a calendar-plausible YYMMDD date.
     */
    private function isCalendarDate(string $yymmdd): bool
    {
        if (\strlen($yymmdd) !== 6 || !ctype_digit($yymmdd)) {
            return false;
        }
        $mm = (int) substr($yymmdd, 2, 2);
        $dd = (int) substr($yymmdd, 4, 2);

        return $mm >= 1 && $mm <= 12 && $dd >= 1 && $dd <= 31;
    }

    /**
     * Robustly recover a French CNI MRZ from noisy OCR. Line 1 is anchored on
     * the `IDFRA` marker; line 2 is anchored on the first 12-digit run whose
     * ICAO check digit validates — that pins the true field grid regardless of
     * stray leading/trailing characters OCR appended. Both lines are padded to
     * the canonical 36 and handed to the structured parser.
     */
    private function parseFrenchFromRaw(string $raw): ?MrzResult
    {
        $upper = strtoupper(str_replace(["\r\n", "\r"], "\n", $raw));
        $cleaned = [];
        foreach (explode("\n", $upper) as $l) {
            $c = preg_replace('/[^A-Z0-9<]/', '', $l) ?? '';
            if (\strlen($c) >= 20) {
                $cleaned[] = $c;
            }
        }

        $line1 = null;
        foreach ($cleaned as $c) {
            $p = strpos($c, 'IDFRA');
            if ($p !== false) {
                $line1 = str_pad(substr($c, $p, 36), 36, '<');
                break;
            }
        }
        if ($line1 === null) {
            return null;
        }

        $line2 = null;
        foreach ($cleaned as $c) {
            $len = \strlen($c);
            for ($i = 0; $i + 13 <= $len; $i++) {
                $num = substr($c, $i, 12);
                if (!ctype_digit($num)) {
                    continue;
                }
                if ($this->checkDigit($num) === $c[$i + 12]) {
                    $line2 = str_pad(substr($c, $i, 36), 36, '<');
                    break 2;
                }
            }
        }
        if ($line2 === null) {
            return null;
        }

        return $this->parseFrenchId([$line1, $line2]);
    }

    /**
     * The pre-2021 French national ID card ("carte nationale d'identité",
     * 1994 model) carries a France-specific 2×36 MRZ that predates and differs
     * from ICAO TD1/TD2. Its signature is the literal `IDFRA` prefix followed
     * by the surname directly in line 1.
     */
    private function isFrenchId(string $line1): bool
    {
        return str_starts_with($line1, 'IDFRA');
    }

    /**
     * Parse the French 1994 CNI MRZ. Layout (2 lines × 36):
     *   line 1: `IDFRA` + surname(25, `<`-padded) + 6-digit admin prefix
     *   line 2: docNumber(12) + check(1) + givenNames(14) + DOB(6) + check(1)
     *           + sex(1) + final check(1)
     * Check digits use the standard ICAO 7-3-1 weighting, so we can still
     * repair OCR slips on the document number and date of birth. There is no
     * expiry date encoded in this format.
     *
     * @param list<string> $lines
     */
    private function parseFrenchId(array $lines): ?MrzResult
    {
        $line1 = $lines[0];
        $line2 = $lines[1];

        $lastName = trim(str_replace('<', ' ', rtrim(substr($line1, 5, 25), '<')));

        $givenRaw = rtrim(substr($line2, 13, 14), '<');
        $firstName = trim((string) preg_replace('/\s+/', ' ', str_replace('<', ' ', $givenRaw)));

        $docNumCheck = $line2[12] ?? '';
        $docNumRepaired = $this->repairCheckDigit(substr($line2, 0, 12), $docNumCheck);
        $documentNumber = $this->stripFillers($docNumRepaired);

        $dobRaw = $this->repairCheckDigit(substr($line2, 27, 6), $line2[33] ?? '', isDate: true);
        $sex = $line2[34] ?? '<';

        $birthDate = $this->parseDate($dobRaw, pastOnly: true);
        if ($birthDate === null || $lastName === '' || $documentNumber === '') {
            return null;
        }

        $confidence = 5; // nationality is implicitly FRA for this format
        if (ctype_digit($docNumCheck) && $this->checkDigit($docNumRepaired) === $docNumCheck) {
            $confidence += 10;
        }
        if (ctype_digit($line2[33] ?? '') && $this->checkDigit($dobRaw) === $line2[33]) {
            $confidence += 10;
        }
        if (\in_array($sex, ['M', 'F'], true)) {
            $confidence += 2;
        }
        $confidence += $this->nameQuality($lastName, $firstName);

        return new MrzResult(
            lastName: $lastName,
            firstName: $firstName,
            birthDate: $birthDate,
            sex: $this->normalizeSex($sex),
            nationalityCode: 'FRA',
            documentType: DocumentType::ID_CARD,
            documentNumber: $documentNumber,
            expiryDate: null,
            confidence: $confidence,
        );
    }

    /**
     * @param list<string> $lines 2 lines (TD3 = 44 chars, TD2 = 36 chars)
     */
    private function parseTwoLine(array $lines): ?MrzResult
    {
        $line1 = $lines[0];
        $line2 = $lines[1];
        $len = \strlen($line1);

        $docTypeChar = $line1[0];
        $isPassport = $docTypeChar === 'P';

        $namePart = substr($line1, 5);
        [$lastName, $firstName] = $this->parseName($namePart);

        $docNumRaw = substr($line2, 0, 9);
        $docNumCheck = $line2[9] ?? '';
        $docNumRepaired = $this->repairCheckDigit($docNumRaw, $docNumCheck);
        $documentNumber = $this->stripFillers($docNumRepaired);
        $nationalityRaw = $this->stripFillers(substr($line2, 10, 3));
        $dobRaw = $this->repairCheckDigit(substr($line2, 13, 6), $line2[19] ?? '', isDate: true);
        $sex = $line2[20] ?? '<';
        $expiryRaw = $this->repairCheckDigit(substr($line2, 21, 6), $line2[27] ?? '', isDate: true);

        $birthDate = $this->parseDate($dobRaw, pastOnly: true);
        if ($birthDate === null || $lastName === '' || $documentNumber === '') {
            return null;
        }

        $confidence = 0;
        if (ctype_digit($docNumCheck) && $this->checkDigit($docNumRepaired) === $docNumCheck) {
            $confidence += 10;
        }
        if (ctype_digit($line2[19] ?? '') && $this->checkDigit($dobRaw) === $line2[19]) {
            $confidence += 10;
        }
        if ($len >= 44 && ctype_digit($line2[27] ?? '') && $this->checkDigit($expiryRaw) === $line2[27]) {
            $confidence += 10;
        }
        if (preg_match('/^[A-Z]{3}$/', $nationalityRaw) === 1) {
            $confidence += 5;
        }
        if (\in_array($sex, ['M', 'F'], true)) {
            $confidence += 2;
        }
        $issuing = substr($line1, 2, 3);
        if (preg_match('/^[A-Z]{3}$/', $issuing) === 1 && $issuing === substr($line2, 10, 3)) {
            $confidence += 5;
        }
        $confidence += $this->nameQuality($lastName, $firstName);

        return new MrzResult(
            lastName: $lastName,
            firstName: $firstName,
            birthDate: $birthDate,
            sex: $this->normalizeSex($sex),
            nationalityCode: $this->mapNationality($nationalityRaw),
            documentType: $isPassport ? DocumentType::PASSPORT : DocumentType::ID_CARD,
            documentNumber: $documentNumber,
            expiryDate: $this->parseDate($expiryRaw, pastOnly: false),
            confidence: $confidence,
        );
    }

    /**
     * @param list<string> $lines 3 lines × 30 chars (TD1)
     */
    private function parseTd1(array $lines): ?MrzResult
    {
        $line1 = $lines[0];
        $line2 = $lines[1];
        $line3 = $lines[2];

        $docTypeChar = $line1[0];

        $docNumCheck = $line1[14] ?? '';
        $docNumRepaired = $this->repairCheckDigit(substr($line1, 5, 9), $docNumCheck);
        $documentNumber = $this->stripFillers($docNumRepaired);

        $dobRaw = $this->repairCheckDigit(substr($line2, 0, 6), $line2[6] ?? '', isDate: true);
        $sex = $line2[7] ?? '<';
        $expiryRaw = $this->repairCheckDigit(substr($line2, 8, 6), $line2[14] ?? '', isDate: true);
        $nationalityRaw = $this->stripFillers(substr($line2, 15, 3));

        [$lastName, $firstName] = $this->parseName($line3);

        $birthDate = $this->parseDate($dobRaw, pastOnly: true);
        if ($birthDate === null || $lastName === '' || $documentNumber === '') {
            return null;
        }

        $documentType = ($docTypeChar === 'P')
            ? DocumentType::PASSPORT
            : (($docTypeChar === 'I' || $docTypeChar === 'A' || $docTypeChar === 'C')
                ? DocumentType::ID_CARD
                : DocumentType::ID_CARD);

        $confidence = 0;
        if (ctype_digit($docNumCheck) && $this->checkDigit($docNumRepaired) === $docNumCheck) {
            $confidence += 10;
        }
        if (ctype_digit($line2[6] ?? '') && $this->checkDigit($dobRaw) === $line2[6]) {
            $confidence += 10;
        }
        if (ctype_digit($line2[14] ?? '') && $this->checkDigit($expiryRaw) === $line2[14]) {
            $confidence += 10;
        }
        if (preg_match('/^[A-Z]{3}$/', $nationalityRaw) === 1) {
            $confidence += 5;
        }
        if (\in_array($sex, ['M', 'F'], true)) {
            $confidence += 2;
        }
        $issuing = substr($line1, 2, 3);
        if (preg_match('/^[A-Z]{3}$/', $issuing) === 1 && $issuing === substr($line2, 15, 3)) {
            $confidence += 5;
        }
        $confidence += $this->nameQuality($lastName, $firstName);

        return new MrzResult(
            lastName: $lastName,
            firstName: $firstName,
            birthDate: $birthDate,
            sex: $this->normalizeSex($sex),
            nationalityCode: $this->mapNationality($nationalityRaw),
            documentType: $documentType,
            documentNumber: $documentNumber,
            expiryDate: $this->parseDate($expiryRaw, pastOnly: false),
            confidence: $confidence,
        );
    }

    /**
     * @return array{string, string} [lastName, firstName]
     */
    private function parseName(string $raw): array
    {
        $cleaned = rtrim($raw, '<');

        if (preg_match('/[A-Z]<<[A-Z]/', $cleaned) !== 1) {
            $cleaned = $this->fixOcrKInName($raw);
        }

        $parts = explode('<<', $cleaned, 2);
        $lastName = str_replace('<', ' ', $parts[0] ?? '');
        $firstRaw = ltrim($parts[1] ?? '', '<');
        if (preg_match('/<{2,}/', $firstRaw, $m, PREG_OFFSET_CAPTURE)) {
            $firstRaw = substr($firstRaw, 0, $m[0][1]);
        }
        $firstRaw = $this->fixOcrKInFirstName($firstRaw);
        $firstName = str_replace('<', ' ', $firstRaw);

        return [trim($lastName), trim($firstName)];
    }

    /**
     * Tesseract often misreads MRZ `<` as `K`. This fixes the name field
     * when no `<<` separator was found (= all fillers read as K).
     */
    private function fixOcrKInName(string $raw): string
    {
        $raw = (string) preg_replace('/[<K]{2,}$/', '', $raw);

        if (str_contains($raw, 'KK')) {
            $raw = (string) preg_replace('/KK/', '<<', $raw, 1);
        } elseif (preg_match('/<[KLCI][A-Z]{3,}/', $raw) === 1) {
            $raw = (string) preg_replace('/<[KLCI]([A-Z]{3,})/', '<<$1', $raw, 1);
        }

        return $raw;
    }

    private function fixOcrKInFirstName(string $firstPart): string
    {
        $trimmed = rtrim($firstPart, '<');
        if ($trimmed === '') {
            return '';
        }

        if (!str_contains($trimmed, '<') && preg_match('/[A-Z]{2,}K[A-Z]{2,}/', $trimmed)) {
            $trimmed = (string) preg_replace('/(?<=[A-Z]{2})K(?=[A-Z]{2})/', '<', $trimmed);
        }

        return $trimmed;
    }

    private function parseDate(string $raw, bool $pastOnly): ?\DateTimeImmutable
    {
        $raw = $this->stripFillers($raw);
        if (\strlen($raw) !== 6 || !ctype_digit($raw)) {
            return null;
        }

        $yy = (int) substr($raw, 0, 2);
        $mm = (int) substr($raw, 2, 2);
        $dd = (int) substr($raw, 4, 2);

        $currentYear = (int) date('y');
        $currentCentury = (int) date('Y') - $currentYear;

        if ($pastOnly) {
            $yyyy = ($yy <= $currentYear) ? $currentCentury + $yy : $currentCentury - 100 + $yy;
        } else {
            $yyyy = 2000 + $yy;
        }

        if ($mm < 1 || $mm > 12 || $dd < 1 || $dd > 31) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $yyyy, $mm, $dd))
            ?: null;
    }

    private function mapNationality(string $icaoCode): string
    {
        if ($icaoCode === '') {
            return '';
        }

        return self::ICAO_TO_ISO[$icaoCode] ?? $icaoCode;
    }

    private function normalizeSex(string $sex): string
    {
        return match ($sex) {
            'M' => 'M',
            'F' => 'F',
            default => '',
        };
    }

    private function stripFillers(string $s): string
    {
        return rtrim($s, '<');
    }

    private function padOrTrim(string $line, int $len): string
    {
        if (\strlen($line) > $len) {
            return substr($line, 0, $len);
        }

        return str_pad($line, $len, '<');
    }
}
